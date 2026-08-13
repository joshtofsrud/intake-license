#!/bin/bash
# apply-gift-cards-addon-gate.sh
#
# MARKER-GIFTCARDS-GATE — makes gift cards a gate-able add-on. Requires
# gift-cards patches 1-3 (core, admin, public) applied first.
#
# THE MODEL (agreed with Josh Aug 12):
#   - New addon 'gift_cards' (category retail): price 0 for now (Josh sets
#     it later, same as ecommerce), included_in_plans ['scale'] — "it will
#     come with Scale" — grantable to anyone from master admin.
#   - SELLING is gated; SPENDING is not. Outstanding cards are money
#     customers already paid, so revoking the addon stops NEW sales but
#     never strands balances:
#       gated by gift_cards_enabled (the addon): register sell button +
#         sale-line activation (server-enforced), admin manual issue,
#         public /gift-cards buy page
#       visible while enabled OR any card exists (gift_cards_visible):
#         register Gift-card tender, admin manager nav + pages
#       never gated: redemption itself, the public balance check
set -e

MARKER="MARKER-GIFTCARDS-GATE"

for need in "app/Services/Tenant/GiftCardService.php" "app/Http/Controllers/Tenant/GiftCardController.php" "app/Http/Controllers/Tenant/GiftCardPublicController.php"; do
  [ -f "$need" ] || { echo "ERROR: requires gift-cards patches 1-3 first (missing $need)"; exit 1; }
done
if grep -q "$MARKER" app/Models/Tenant.php 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Addon row (data migration, same shape as extended_reports)
# ---------------------------------------------------------------
cat > database/migrations/2026_08_12_100000_add_gift_cards_addon.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-GIFTCARDS-GATE — gift cards become a gate-able add-on, included
 * with Scale. Price deliberately 0: Josh sets pricing later (same call as
 * the ecommerce addon — inventing a number in a migration would be worse).
 * Selling is gated; redemption and the public balance check are not, so
 * revoking the add-on can never strand balances customers already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('addons')->insert([
            'code' => 'gift_cards',
            'name' => 'Gift Cards',
            'category' => 'retail',
            'description' => 'Sell physical and e-gift cards at the register and online. Internal balance ledger, live balance check on your website, scheduled e-gift delivery. Included free with Scale.',
            'tooltip' => 'Physical + e-gift cards with a full balance ledger. Existing card balances always stay redeemable, even if the add-on is later removed.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => null,
            'included_in_plans' => json_encode(['scale']),
            'sort_order' => 145,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'gift_cards')->delete();
    }
};
EOF
echo "ok: addon migration created"

# ---------------------------------------------------------------
# 2. Tenant accessors — enabled (addon) + visible (addon OR cards exist)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Models/Tenant.php'
src = io.open(p, encoding='utf-8').read()
a = """    public function getOnlineStoreEnabledAttribute(): bool
    {
        return app(\\App\\Services\\FeatureAccessService::class)->hasAddon($this, 'online_store');
    }
"""
assert src.count(a) == 1
src = src.replace(a, a + """
    // MARKER-GIFTCARDS-GATE -- selling is gated by the addon; the manager
    // and the tender stay visible while any card exists, so a revoked
    // tenant can still honor and administer outstanding balances.
    public function getGiftCardsEnabledAttribute(): bool
    {
        return app(\\App\\Services\\FeatureAccessService::class)->hasAddon($this, 'gift_cards');
    }

    public function getGiftCardsVisibleAttribute(): bool
    {
        return $this->gift_cards_enabled
            || \\App\\Models\\Tenant\\TenantGiftCard::where('tenant_id', $this->id)->exists();
    }
""", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: Tenant accessors added')
PY

# ---------------------------------------------------------------
# 3. Server enforcement
# ---------------------------------------------------------------
python3 - <<'PY'
import io

# 3a. Activation path: selling a card on a sale requires the addon.
p = 'app/Services/Tenant/GiftCardService.php'
src = io.open(p, encoding='utf-8').read()
a = """        foreach ($sale->items as $line) {
            if ($line->type !== 'gift_card' || $line->gift_card_id !== null) {
                continue;
            }
"""
assert src.count(a) == 1
src = src.replace(a, """        foreach ($sale->items as $line) {
            if ($line->type !== 'gift_card' || $line->gift_card_id !== null) {
                continue;
            }

            // MARKER-GIFTCARDS-GATE -- selling requires the addon. Checked at
            // activation so it holds for every path (register, drafts, quotes
            // committed later). Redemption is deliberately NOT gated.
            if (! \\App\\Models\\Tenant::find($sale->tenant_id)?->gift_cards_enabled) {
                throw new SaleValidationException('Gift cards are not enabled for this shop.');
            }
""", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: issueForSale addon check')

# 3b. Admin: manager pages require visible; manual issue requires enabled.
p2 = 'app/Http/Controllers/Tenant/GiftCardController.php'
s2 = io.open(p2, encoding='utf-8').read()
a2 = """    public function index(Request $request)
    {
        $tenant = tenant();
"""
assert s2.count(a2) == 1
s2 = s2.replace(a2, a2 + "        abort_unless($tenant->gift_cards_visible, 404); // MARKER-GIFTCARDS-GATE\n", 1)
a3 = """    public function show(Request $request, string $cardId)
    {
        $tenant = tenant();
"""
assert s2.count(a3) == 1
s2 = s2.replace(a3, a3 + "        abort_unless($tenant->gift_cards_visible, 404); // MARKER-GIFTCARDS-GATE\n", 1)
a4 = """        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $tenant = tenant();
"""
assert s2.count(a4) == 1  # store() only; adjust/deactivate fetch tenant inline
s2 = s2.replace(a4, a4 + "        abort_unless($tenant->gift_cards_enabled, 404); // MARKER-GIFTCARDS-GATE -- manual issue = selling\n", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: admin controller gates')

# 3c. Public buy page: swap the online_store guard for the gift_cards addon.
p3 = 'app/Http/Controllers/Tenant/GiftCardPublicController.php'
s3 = io.open(p3, encoding='utf-8').read()
a5 = """    /** Buy page rides the storefront addon, like /shop. */
    protected function guardShop(): void
    {
        $ok = app(\\App\\Services\\FeatureAccessService::class)->hasAddon(tenant(), 'online_store');
        abort_unless($ok, 404);
    }
"""
assert s3.count(a5) == 1
s3 = s3.replace(a5, """    /** MARKER-GIFTCARDS-GATE -- buy page rides the gift_cards addon (its own
     * gate, independent of the product storefront: a shop can sell gift cards
     * online without running ecommerce). Balance check stays ungated. */
    protected function guardShop(): void
    {
        abort_unless(tenant()->gift_cards_enabled, 404);
    }
""", 1)
io.open(p3, 'w', encoding='utf-8').write(s3)
print('ok: public buy gate swapped to gift_cards')
PY

# ---------------------------------------------------------------
# 4. UI gates — nav entry, sell button, tender button
# ---------------------------------------------------------------
python3 - <<'PY'
import io

# 4a. nav gate: retail_enabled -> gift_cards_visible
p = 'resources/views/layouts/tenant/_nav-items.blade.php'
src = io.open(p, encoding='utf-8').read()
a = """      'route'  => 'tenant.gift-cards.index',
      'label'  => 'Gift Cards',
"""
assert src.count(a) == 1
i = src.find(a)
j = src.find("'gate'   => 'retail_enabled',", i)
assert 0 < j < i + 800, 'gift-cards nav gate line not found near entry'
src = src[:j] + "'gate'   => 'gift_cards_visible', // MARKER-GIFTCARDS-GATE" + src[j + len("'gate'   => 'retail_enabled',"):]
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: nav gate -> gift_cards_visible')

# 4b. register: wrap sell button (enabled) and tender button (visible)
p2 = 'resources/views/tenant/register/index.blade.php'
s2 = io.open(p2, encoding='utf-8').read()
a2 = '<button type="button" class="reg-open-item" id="sellGiftCardBtn">+ Sell gift card</button> {{-- MARKER-GIFTCARDS --}}'
assert s2.count(a2) == 1
s2 = s2.replace(a2, '@if(tenant()->gift_cards_enabled)<button type="button" class="reg-open-item" id="sellGiftCardBtn">+ Sell gift card</button>@endif {{-- MARKER-GIFTCARDS-GATE --}}', 1)
a3 = '<button type="button" class="reg-tender-btn" data-tender="gift_card">Gift card</button> {{-- MARKER-GIFTCARDS --}}'
assert s2.count(a3) == 1
s2 = s2.replace(a3, '@if(tenant()->gift_cards_visible)<button type="button" class="reg-tender-btn" data-tender="gift_card">Gift card</button>@endif {{-- MARKER-GIFTCARDS-GATE --}}', 1)

# 4c. sell-modal JS binds unconditionally; guard for the button now being absent
a4 = "document.getElementById('sellGiftCardBtn').addEventListener('click', () => {"
assert s2.count(a4) == 1
s2 = s2.replace(a4, "if (document.getElementById('sellGiftCardBtn')) document.getElementById('sellGiftCardBtn').addEventListener('click', () => {", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: register sell/tender buttons gated + JS guard')
PY

echo ""
echo "== gift-cards addon gate applied =="
echo "Post-deploy: php artisan optimize:clear. Grant/revoke from master admin"
echo "Features tab (category retail renders since MARKER-FGCATS). Scale tenants"
echo "have it included automatically."
