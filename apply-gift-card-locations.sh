#!/bin/bash
# apply-gift-card-locations.sh
#
# MARKER-GC-LOCATION — multi-location attribution for gift cards.
#
# WHAT THIS DELIBERATELY IS NOT: a pickup-location picker on the public buy
# page. Josh confirmed pickup location is a CHECKOUT-level choice made near
# the end of checkout that applies to the whole sale — and tenant_orders
# already carries a nullable location_id waiting for it. Building a separate
# picker on /gift-cards would be a second place to maintain and could give a
# different answer than the shop checkout for the same customer. Online
# physical cards keep a null location until that step exists; the column is
# here ready for it.
#
# ALSO NOT INCLUDED, after Josh pushed back and he was right: per-store
# liability tiles or a location-scoped default on the list. Selling a gift
# card is cash in and a liability out, not a sale of goods — the revenue
# lands wherever it is later spent, and the card is spendable at any
# location, so a per-store outstanding balance would be misleading.
#
# WHAT IT DOES:
#   - location_id (nullable) on tenant_gift_cards and on the ledger table.
#   - Cards stamped at issue: the sale's location for register sales, the
#     staff member's current location for a manual issue, the refund sale's
#     location for refund-to-card.
#   - Ledger rows stamped from whichever sale drove them, so "which store
#     gave up goods against this card" is a direct query rather than a join
#     back through sales. That is the side that maps to real revenue.
#   - Existing rows backfilled from issued_sale_id / sale_id.
#   - Location shown on the card detail, where the question gets asked.
set -e

MARKER="MARKER-GC-LOCATION"
SVC="app/Services/Tenant/GiftCardService.php"

[ -f "$SVC" ] || { echo "ERROR: requires the gift card patches"; exit 1; }
if grep -q "$MARKER" "$SVC" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Columns + backfill
# ---------------------------------------------------------------
cat > database/migrations/2026_08_14_090000_add_location_to_gift_cards.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-GC-LOCATION — which location issued a card, and which location a
 * redemption happened at.
 *
 * Nullable on purpose: a card bought online has no location until the
 * checkout-level pickup choice exists, and a tenant may have no locations
 * configured at all. Nothing reads these as required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_gift_cards', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('issued_by_user_id'); // issuing location
            $table->index(['tenant_id', 'location_id'], 'tgc_tenant_location_idx');
        });

        Schema::table('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('sale_id'); // where this movement happened
        });

        // Backfill from the sale each row already points at, so existing
        // cards are not blank for everything sold to date.
        DB::statement("
            UPDATE tenant_gift_cards gc
            JOIN tenant_sales s ON s.id = gc.issued_sale_id
            SET gc.location_id = s.location_id
            WHERE gc.location_id IS NULL AND s.location_id IS NOT NULL
        ");

        DB::statement("
            UPDATE tenant_gift_card_transactions t
            JOIN tenant_sales s ON s.id = t.sale_id
            SET t.location_id = s.location_id
            WHERE t.location_id IS NULL AND s.location_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tenant_gift_cards', function (Blueprint $table) {
            $table->dropIndex('tgc_tenant_location_idx');
            $table->dropColumn('location_id');
        });
        Schema::table('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
EOF
echo "ok: migration + backfill"

# ---------------------------------------------------------------
# 2. Models
# ---------------------------------------------------------------
python3 - <<'PY'
import io

p = 'app/Models/Tenant/TenantGiftCard.php'
src = io.open(p, encoding='utf-8').read()
a = "        'issued_sale_id', 'issued_by_user_id', 'stripe_payment_intent_id',"
assert src.count(a) == 1
src = src.replace(a, a + "\n        'location_id', // MARKER-GC-LOCATION", 1)

b = "    public function purchaser()"
assert src.count(b) == 1
src = src.replace(b, """    // MARKER-GC-LOCATION -- issuing location; null for online buys until the
    // checkout-level pickup choice exists.
    public function location()
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    public function purchaser()""", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: TenantGiftCard')

p2 = 'app/Models/Tenant/TenantGiftCardTransaction.php'
s2 = io.open(p2, encoding='utf-8').read()
c = "        'balance_after_cents', 'sale_id', 'note', 'user_id',"
assert s2.count(c) == 1
s2 = s2.replace(c, "        'balance_after_cents', 'sale_id', 'note', 'user_id',\n        'location_id', // MARKER-GC-LOCATION", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: TenantGiftCardTransaction')
PY

# ---------------------------------------------------------------
# 3. Service: stamp at issue and on every ledger row
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Services/Tenant/GiftCardService.php'
src = io.open(p, encoding='utf-8').read()

# 3a. ledger() takes the location the movement happened at.
a = """    protected function ledger(TenantGiftCard $card, string $kind, int $amountCents, ?string $saleId, ?string $note, ?string $userId): void
    {
        TenantGiftCardTransaction::create([
            'tenant_id'           => $card->tenant_id,
            'gift_card_id'        => $card->id,
            'kind'                => $kind,
            'amount_cents'        => $amountCents,
            'balance_after_cents' => $card->balance_cents,
            'sale_id'             => $saleId,
            'note'                => $note,
            'user_id'             => $userId,
        ]);
    }"""
assert src.count(a) == 1
src = src.replace(a, """    protected function ledger(TenantGiftCard $card, string $kind, int $amountCents, ?string $saleId, ?string $note, ?string $userId, ?string $locationId = null): void
    {
        // MARKER-GC-LOCATION -- when the movement came from a sale, take that
        // sale's location rather than the staff member's current one: a
        // redemption belongs to the store that gave up the goods.
        if ($locationId === null && $saleId !== null) {
            $locationId = TenantSale::whereKey($saleId)->value('location_id');
        }

        TenantGiftCardTransaction::create([
            'tenant_id'           => $card->tenant_id,
            'gift_card_id'        => $card->id,
            'kind'                => $kind,
            'amount_cents'        => $amountCents,
            'balance_after_cents' => $card->balance_cents,
            'sale_id'             => $saleId,
            'location_id'         => $locationId, // MARKER-GC-LOCATION
            'note'                => $note,
            'user_id'             => $userId,
        ]);
    }""", 1)

# 3b. cards sold on a register sale inherit that sale's location
b = """                'tenant_id'             => $sale->tenant_id,"""
assert src.count(b) == 1, 'issueForSale tenant_id line not found'
src = src.replace(b, b + """
                // MARKER-GC-LOCATION -- the register that rang the sale.
                'location_id'           => $sale->location_id,""", 1)

# 3c. refund-to-card: new card belongs to the refunding location
c = """        $card = TenantGiftCard::create([
            'tenant_id'       => $refund->tenant_id,
            'code'            => $this->generateCode($refund->tenant_id),
            'type'            => 'physical',
            'status'          => 'active',
            'original_cents'  => $amount,
            'balance_cents'   => $amount,
            'issued_sale_id'  => $refund->id,
        ]);"""
assert src.count(c) == 1
src = src.replace(c, """        $card = TenantGiftCard::create([
            'tenant_id'       => $refund->tenant_id,
            'code'            => $this->generateCode($refund->tenant_id),
            'type'            => 'physical',
            'status'          => 'active',
            'original_cents'  => $amount,
            'balance_cents'   => $amount,
            'issued_sale_id'  => $refund->id,
            'location_id'     => $refund->location_id, // MARKER-GC-LOCATION
        ]);""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: GiftCardService stamps location')
PY

# ---------------------------------------------------------------
# 4. Manual issue uses the staff member's current location
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/GiftCardController.php'
src = io.open(p, encoding='utf-8').read()

a = """        $card = \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($tenant, $data, $amount, $code) {"""
assert src.count(a) == 1
src = src.replace(a, """        // MARKER-GC-LOCATION -- a manual issue has no sale behind it, so
        // attribute it to wherever the staff member is working. Falls back to
        // the default location, then to none at all (single-location tenants
        // that never configured one).
        $issueLocationId = $request->session()->get('current_location_id')
            ?: $tenant->locations()->where('is_active', true)
                ->orderByDesc('is_default')->value('id');

        $card = \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($tenant, $data, $amount, $code, $issueLocationId) {""", 1)

b = """                'issued_by_user_id' => auth('tenant')->id(),
            ]);"""
assert src.count(b) == 1
src = src.replace(b, """                'issued_by_user_id' => auth('tenant')->id(),
                'location_id'       => $issueLocationId, // MARKER-GC-LOCATION
            ]);""", 1)

c = """                'note'                => 'Issued manually' . (filled($data['note'] ?? null) ? ' — ' . $data['note'] : ''),
                'user_id'             => auth('tenant')->id(),"""
assert src.count(c) == 1
src = src.replace(c, """                'note'                => 'Issued manually' . (filled($data['note'] ?? null) ? ' — ' . $data['note'] : ''),
                'user_id'             => auth('tenant')->id(),
                'location_id'         => $issueLocationId, // MARKER-GC-LOCATION""", 1)

# eager-load the location for the detail page
d = """    public function show(Request $request, string $cardId)
    {
        $tenant = tenant();"""
assert src.count(d) == 1
src = src.replace(d, d + "\n        // MARKER-GC-LOCATION -- shown in the header when the shop has 2+ locations.", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: manual issue location')
PY

# ---------------------------------------------------------------
# 5. Card detail shows it (only when it means something)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/tenant/gift-cards/show.blade.php'
src = io.open(p, encoding='utf-8').read()

a = """      · Issued {{ tlocal_date($card->created_at) }}{{-- MARKER-GC-TLOCAL --}}"""
assert src.count(a) == 1
src = src.replace(a, """      · Issued {{ tlocal_date($card->created_at) }}{{-- MARKER-GC-TLOCAL --}}
      {{-- MARKER-GC-LOCATION -- pointless noise for a single-location shop --}}
      @if(tenant()->multi_location_active && $card->location) · at {{ $card->location->name }} @endif""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: detail header')
PY

echo ""
echo "== gift card locations applied =="
echo "Post-deploy: migrations run in deploy; php artisan optimize:clear"
