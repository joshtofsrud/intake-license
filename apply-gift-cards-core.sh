#!/bin/bash
# apply-gift-cards-core.sh
#
# MARKER-GIFTCARDS — gift cards patch 1 of 3: schema + ledger service +
# register sell & redeem. Built to the approved intake-gift-cards-mockup.html.
#
#   - tenant_gift_cards + tenant_gift_card_transactions (every balance change
#     is a ledger row; balance_cents is a cache the ledger can rebuild)
#   - metadata JSON column on tenant_sale_items: a gift_card cart line carries
#     its gift details (type/code/recipient) on the DRAFT row, so drafts and
#     quotes survive and commitDraft activates from what the row itself says
#   - GiftCardService: issue / redeem / adjust / deactivate / lookup, all
#     lockForUpdate inside the caller's transaction
#   - Cards ACTIVATE ON SALE COMPLETION (createSale + commitDraft hooks),
#     never on line-add. Redemption debits INSIDE the sale transaction and
#     throws on short balance BEFORE the fail-open payment-ledger block.
#   - Register: "+ Sell gift card" button + modal (physical scan/type code,
#     e-gift recipient + message), "Gift card" tender with live balance check;
#     short balance drops to split for the remainder (no forced sequence).
#   - Mixed refund transactions REJECT gift_card tender (422 + client error)
#     rather than silently not debiting — refund-to-card is a later patch;
#     admin balance adjustments (patch 2) cover it meanwhile.
#   - E-gift sold at the register emails immediately via DeliverGiftCardJob.
set -e

MARKER="MARKER-GIFTCARDS"
SVC="app/Services/Tenant/SaleService.php"
CTRL="app/Http/Controllers/Tenant/RegisterController.php"
IDX="resources/views/tenant/register/index.blade.php"

if grep -q "$MARKER" "$SVC" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Migrations
# ---------------------------------------------------------------
cat > database/migrations/2026_08_11_100000_create_tenant_gift_cards_table.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — one row per card. balance_cents is a cache; the
// transactions ledger is the source of truth and can rebuild it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('code', 40);                  // normalized, stored uppercase
            $table->string('type', 12);                  // physical | egift
            $table->string('status', 16)->default('active'); // pending | active | used | deactivated

            $table->integer('original_cents');
            $table->integer('balance_cents');

            $table->uuid('purchaser_customer_id')->nullable();
            $table->string('purchaser_name')->nullable();   // online buys with no customer row
            $table->string('purchaser_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('gift_message')->nullable();
            $table->date('deliver_on')->nullable();         // tenant-local; null = immediately
            $table->timestamp('delivered_at')->nullable();

            $table->uuid('issued_sale_id')->nullable();     // register sale that sold it
            $table->uuid('issued_by_user_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable(); // online purchase (patch 3)

            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivated_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gift_cards');
    }
};
EOF

cat > database/migrations/2026_08_11_100100_create_tenant_gift_card_transactions_table.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — the ledger. amount_cents is signed (+credit / −debit);
// balance_after_cents lets the detail screen render running balances without
// re-summing on every row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('gift_card_id')->constrained('tenant_gift_cards')->cascadeOnDelete();

            $table->string('kind', 16);                 // issue | redeem | adjust | deactivate
            $table->integer('amount_cents');            // signed
            $table->integer('balance_after_cents');
            $table->uuid('sale_id')->nullable();
            $table->string('note')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->index(['gift_card_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gift_card_transactions');
    }
};
EOF

cat > database/migrations/2026_08_11_100200_add_metadata_to_tenant_sale_items.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — per-line metadata. First use: a gift_card line stores
// its gift details here on the DRAFT row, so drafts/quotes survive and
// commitDraft activates from the row itself. Additive (expand/contract rule).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
EOF
echo "ok: 3 migrations created"

# ---------------------------------------------------------------
# 2. Models
# ---------------------------------------------------------------
cat > app/Models/Tenant/TenantGiftCard.php <<'EOF'
<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// MARKER-GIFTCARDS
class TenantGiftCard extends Model
{
    use HasUuids;

    protected $table = 'tenant_gift_cards';

    protected $fillable = [
        'tenant_id', 'code', 'type', 'status',
        'original_cents', 'balance_cents',
        'purchaser_customer_id', 'purchaser_name', 'purchaser_email',
        'recipient_name', 'recipient_email', 'gift_message',
        'deliver_on', 'delivered_at',
        'issued_sale_id', 'issued_by_user_id', 'stripe_payment_intent_id',
        'deactivated_at', 'deactivated_reason',
    ];

    protected $casts = [
        'deliver_on'     => 'date',
        'delivered_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(TenantGiftCardTransaction::class, 'gift_card_id')->orderByDesc('created_at');
    }

    /** Normalize a typed/scanned code for lookup and storage. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', trim($code)));
    }

    public function maskedCode(): string
    {
        $c = (string) $this->code;
        $tail = substr($c, -4);
        return 'GC-••••-••••-' . $tail;
    }
}
EOF

cat > app/Models/Tenant/TenantGiftCardTransaction.php <<'EOF'
<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-GIFTCARDS
class TenantGiftCardTransaction extends Model
{
    use HasUuids;

    protected $table = 'tenant_gift_card_transactions';

    protected $fillable = [
        'tenant_id', 'gift_card_id', 'kind', 'amount_cents',
        'balance_after_cents', 'sale_id', 'note', 'user_id',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(TenantGiftCard::class, 'gift_card_id');
    }
}
EOF
echo "ok: 2 models created"

# ---------------------------------------------------------------
# 3. GiftCardService
# ---------------------------------------------------------------
cat > app/Services/Tenant/GiftCardService.php <<'EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\Exceptions\SaleValidationException;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-GIFTCARDS — internal gift card ledger. Every balance change writes
 * a transaction row; balance_cents is a cache the ledger can rebuild.
 *
 * All mutators expect to run INSIDE the caller's DB transaction (the sale
 * transaction for issue/redeem) and take their own lockForUpdate on the card
 * row, so two registers can't double-spend one card.
 */
class GiftCardService
{
    /** Generate a unique, unused code for this tenant: GC-####-####-####. */
    public function generateCode(string $tenantId): string
    {
        do {
            $code = sprintf('GC-%04d-%04d-%04d', random_int(0, 9999), random_int(0, 9999), random_int(0, 9999));
        } while (TenantGiftCard::where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }

    /** Balance lookup by code. Returns the card or null. Never leaks other tenants. */
    public function lookup(string $tenantId, string $code): ?TenantGiftCard
    {
        $norm = TenantGiftCard::normalizeCode($code);
        if ($norm === '') {
            return null;
        }

        return TenantGiftCard::where('tenant_id', $tenantId)->where('code', $norm)->first();
    }

    /**
     * Activate gift cards sold on a completed sale. Reads each gift_card
     * line's metadata (written at cart time), creates the card, writes the
     * issue ledger row, links the line, and queues e-gift delivery.
     *
     * Idempotent per line via the gift_card_id null check, so a replayed
     * commit can't double-issue.
     */
    public function issueForSale(TenantSale $sale): void
    {
        foreach ($sale->items as $line) {
            if ($line->type !== 'gift_card' || $line->gift_card_id !== null) {
                continue;
            }

            $meta = (array) ($line->metadata ?? []);
            $kind = ($meta['kind'] ?? 'physical') === 'egift' ? 'egift' : 'physical';
            $amount = (int) round($line->unit_price_cents * (float) $line->quantity);
            if ($amount < 1) {
                throw new SaleValidationException('Gift card amount must be at least $0.01.');
            }

            if ($kind === 'physical') {
                $code = TenantGiftCard::normalizeCode((string) ($meta['code'] ?? ''));
                if ($code === '') {
                    throw new SaleValidationException('Physical gift card is missing its card code.');
                }
                if (TenantGiftCard::where('tenant_id', $sale->tenant_id)->where('code', $code)->exists()) {
                    throw new SaleValidationException("Gift card code {$code} is already in use.");
                }
            } else {
                $code = $this->generateCode($sale->tenant_id);
                if (blank($meta['recipient_email'] ?? null)) {
                    throw new SaleValidationException('E-gift card is missing the recipient email.');
                }
            }

            $card = TenantGiftCard::create([
                'tenant_id'             => $sale->tenant_id,
                'code'                  => $code,
                'type'                  => $kind,
                'status'                => 'active',
                'original_cents'        => $amount,
                'balance_cents'         => $amount,
                'purchaser_customer_id' => $sale->customer_id,
                'recipient_name'        => $meta['recipient_name'] ?? null,
                'recipient_email'       => $meta['recipient_email'] ?? null,
                'gift_message'          => $meta['gift_message'] ?? null,
                'issued_sale_id'        => $sale->id,
                'issued_by_user_id'     => $sale->rang_up_by_user_id,
            ]);

            $this->ledger($card, 'issue', $amount, $sale->id, 'Sold on sale ' . ($sale->sale_number ?? $sale->id), $sale->rang_up_by_user_id);

            $line->update(['gift_card_id' => $card->id]);

            if ($kind === 'egift') {
                \App\Jobs\DeliverGiftCardJob::dispatch($card->id)->afterCommit();
            }
        }
    }

    /**
     * Redeem gift-card tenders for a sale, inside the sale transaction.
     * Reads either payments[] entries with method=gift_card (reference = code)
     * or a single payment_method=gift_card with payment_reference = code.
     * Throws SaleValidationException (→ 422, transaction rolls back) on any
     * unknown code, inactive card, or short balance.
     */
    public function redeemTenders(TenantSale $sale, array $data): void
    {
        $tenders = [];
        foreach ((array) ($data['payments'] ?? []) as $p) {
            if (($p['method'] ?? null) === 'gift_card') {
                $tenders[] = ['code' => (string) ($p['reference'] ?? ''), 'amount' => (int) $p['amount_cents']];
            }
        }
        if (empty($tenders) && ($data['payment_method'] ?? null) === 'gift_card') {
            $tenders[] = ['code' => (string) ($data['payment_reference'] ?? ''), 'amount' => (int) $sale->total_cents];
        }

        foreach ($tenders as $t) {
            $this->redeem($sale, $t['code'], $t['amount']);
        }
    }

    protected function redeem(TenantSale $sale, string $code, int $amountCents): void
    {
        $norm = TenantGiftCard::normalizeCode($code);
        $card = TenantGiftCard::where('tenant_id', $sale->tenant_id)
            ->where('code', $norm)
            ->lockForUpdate()
            ->first();

        if (! $card) {
            throw new SaleValidationException('Gift card ' . ($norm ?: '(blank)') . ' was not found.');
        }
        if ($card->status !== 'active') {
            throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' is not active.');
        }
        if ($amountCents < 1) {
            throw new SaleValidationException('Gift card redemption amount must be positive.');
        }
        if ($card->balance_cents < $amountCents) {
            throw new SaleValidationException(sprintf(
                'Gift card %s has $%s left — the sale asked for $%s. Apply it as a split payment.',
                $card->maskedCode(),
                number_format($card->balance_cents / 100, 2),
                number_format($amountCents / 100, 2)
            ));
        }

        $card->balance_cents -= $amountCents;
        if ($card->balance_cents === 0) {
            $card->status = 'used';
        }
        $card->save();

        $this->ledger($card, 'redeem', -$amountCents, $sale->id, 'Tender on sale ' . ($sale->sale_number ?? $sale->id), $sale->rang_up_by_user_id);
    }

    /** Manual balance adjustment (± cents). Requires a reason. */
    public function adjust(TenantGiftCard $card, int $deltaCents, string $reason, ?string $userId): void
    {
        DB::transaction(function () use ($card, $deltaCents, $reason, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();

            if ($deltaCents === 0) {
                throw new \InvalidArgumentException('Adjustment cannot be zero.');
            }
            if ($locked->balance_cents + $deltaCents < 0) {
                throw new \InvalidArgumentException('Adjustment would take the balance below zero.');
            }

            $locked->balance_cents += $deltaCents;
            $locked->status = $locked->balance_cents === 0 ? 'used'
                : ($locked->status === 'used' ? 'active' : $locked->status);
            $locked->save();

            $this->ledger($locked, 'adjust', $deltaCents, null, $reason, $userId);
        });
    }

    /** Deactivate a card (lost/stolen). Balance is preserved for the record. */
    public function deactivate(TenantGiftCard $card, string $reason, ?string $userId): void
    {
        DB::transaction(function () use ($card, $reason, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();
            if ($locked->status === 'deactivated') {
                return;
            }
            $locked->update([
                'status'             => 'deactivated',
                'deactivated_at'     => now(),
                'deactivated_reason' => $reason,
            ]);
            $this->ledger($locked, 'deactivate', 0, null, $reason, $userId);
        });
    }

    protected function ledger(TenantGiftCard $card, string $kind, int $amountCents, ?string $saleId, ?string $note, ?string $userId): void
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
    }
}
EOF
echo "ok: GiftCardService created"

# ---------------------------------------------------------------
# 4. E-gift delivery job + email view
# ---------------------------------------------------------------
cat > app/Jobs/DeliverGiftCardJob.php <<'EOF'
<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GIFTCARDS — email an e-gift card to its recipient and stamp
 * delivered_at. Dispatched at issue time (immediate delivery) and by the
 * gift-cards:deliver scheduler (scheduled deliver_on dates, patch 3).
 * Idempotent via the delivered_at check.
 */
class DeliverGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $giftCardId)
    {
    }

    public function handle(): void
    {
        $card = TenantGiftCard::find($this->giftCardId);
        if (! $card || $card->delivered_at !== null || $card->status !== 'active' || blank($card->recipient_email)) {
            return;
        }

        $tenant = Tenant::find($card->tenant_id);
        if (! $tenant) {
            return;
        }

        try {
            $html = view('emails.gift-card', [
                'tenant' => $tenant,
                'card'   => $card,
            ])->render();

            $ok = (new EmailService($tenant))->sendRendered(
                'gift_card',
                $card->recipient_email,
                'You\'ve received a ' . $tenant->name . ' gift card',
                $html
            );

            if ($ok) {
                $card->update(['delivered_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('gift_card.delivery_failed', [
                'gift_card_id' => $card->id,
                'tenant_id'    => $card->tenant_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
EOF

cat > resources/views/emails/gift-card.blade.php <<'EOF'
{{-- MARKER-GIFTCARDS — e-gift delivery email, per the approved mockup --}}
@php
  $accent = $tenant->accent_color ?: '#BEF264';
  $amount = '$' . number_format($card->original_cents / 100, 2);
  $from   = $card->purchaser_name ?: null;
@endphp
<div style="background:#f2f2f2;padding:34px 16px;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid rgba(0,0,0,.07);color:#111111;">
    <div style="padding:22px 26px 0;font-weight:700;font-size:15px;">You've received a gift card 🎁</div>
    <div style="padding:18px 26px 26px;font-size:14px;line-height:1.65;">
      <p style="margin:0 0 6px;">
        @if($from)<b>{{ $from }}</b> sent you a gift card to <b>{{ $tenant->name }}</b>.
        @else You've been sent a gift card to <b>{{ $tenant->name }}</b>.@endif
      </p>
      @if(filled($card->gift_message))
        <div style="background:rgba(0,0,0,.035);border-radius:12px;padding:14px 16px;font-style:italic;margin:14px 0;">&ldquo;{{ $card->gift_message }}&rdquo;</div>
      @endif
      <div style="border-radius:16px;padding:22px 24px;background:#161616;color:#ffffff;margin:16px 0;">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;opacity:.55;font-weight:700;">{{ $tenant->name }}</div>
        <div style="font-size:34px;font-weight:800;margin-top:10px;letter-spacing:-.02em;">{{ $amount }}</div>
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-top:14px;">Gift card</div>
        <div style="font-family:ui-monospace,monospace;font-size:14px;letter-spacing:.14em;margin-top:6px;opacity:.85;">{{ $card->code }}</div>
      </div>
      <p style="font-size:13px;opacity:.65;margin:0;">Use this code at checkout online, or show this email in store. Check your balance any time.</p>
    </div>
    <div style="padding:16px 26px;border-top:1px solid rgba(0,0,0,.06);font-size:12px;opacity:.5;">{{ $tenant->name }} · Sent by Intake on behalf of {{ $tenant->name }}</div>
  </div>
</div>
EOF
echo "ok: delivery job + email view created"

# ---------------------------------------------------------------
# 5. SaleService — hooks + metadata passthrough + model fillable
# ---------------------------------------------------------------
python3 - <<'PY'
import io

p = 'app/Services/Tenant/SaleService.php'
src = io.open(p, encoding='utf-8').read()
assert 'MARKER-GIFTCARDS' not in src

hook = """            $finalSale = $this->recalculate($sale->fresh('items'));

            // MARKER-GIFTCARDS -- inside the sale transaction, BEFORE the
            // fail-open payment-ledger block: debit any gift-card tenders
            // (throws on unknown code / short balance, rolling everything
            // back) and activate any gift cards sold on this sale. Paid
            // sales only -- an unpaid sale must never activate a card.
            if (($finalSale->payment_status ?? null) === 'paid') {
                $gifts = app(\\App\\Services\\Tenant\\GiftCardService::class);
                $gifts->redeemTenders($finalSale, $data);
                $gifts->issueForSale($finalSale);
            }
"""

# createSale hook (anchored by $data['location_id'] inventory loop above it)
a1 = """                    $this->inventory->decrementForSaleItem($sale, $line, $data['location_id']);
                }
            }

            $finalSale = $this->recalculate($sale->fresh('items'));
"""
assert src.count(a1) == 1, 'createSale anchor'
src = src.replace(a1, a1.replace("            $finalSale = $this->recalculate($sale->fresh('items'));\n", hook), 1)

# commitDraft hook (anchored by $sale->location_id) -- only when becoming paid
hook2 = """            $finalSale = $this->recalculate($sale->fresh('items'));

            // MARKER-GIFTCARDS -- same as createSale, but only when this
            // commit is actually taking payment (quotes/drafts committing to
            // unpaid states neither debit nor activate).
            if ($newPaymentStatus === 'paid') {
                $gifts = app(\\App\\Services\\Tenant\\GiftCardService::class);
                $gifts->redeemTenders($finalSale, $data);
                $gifts->issueForSale($finalSale);
            }
"""
a2 = """                    $this->inventory->decrementForSaleItem($sale, $line, $sale->location_id);
                }
            }

            $finalSale = $this->recalculate($sale->fresh('items'));
"""
assert src.count(a2) == 1, 'commitDraft anchor'
src = src.replace(a2, a2.replace("            $finalSale = $this->recalculate($sale->fresh('items'));\n", hook2), 1)

# createSaleItem: store gift metadata + force gift lines non-taxable
a3 = "        $giftCardId     = $data['gift_card_id'] ?? null;\n"
assert src.count(a3) == 1
src = src.replace(a3, a3 + """
        // MARKER-GIFTCARDS -- gift lines carry their gift details in metadata
        // (survives drafts/quotes; commitDraft activates from the row). Tax
        // is charged when the card is SPENT, never when it is sold.
        $metadata = null;
        if ($type === 'gift_card') {
            $isTaxable = false;
            $gc = (array) ($data['gift_card'] ?? []);
            $metadata = [
                'kind'            => ($gc['kind'] ?? 'physical') === 'egift' ? 'egift' : 'physical',
                'code'            => isset($gc['code']) ? \\App\\Models\\Tenant\\TenantGiftCard::normalizeCode((string) $gc['code']) : null,
                'recipient_name'  => $gc['recipient_name'] ?? null,
                'recipient_email' => $gc['recipient_email'] ?? null,
                'gift_message'    => $gc['gift_message'] ?? null,
            ];
        }
""", 1)

a4 = "            'gift_card_id'        => $giftCardId,\n"
assert src.count(a4) >= 1
src = src.replace(a4, a4 + "            'metadata'            => $metadata ?? null, // MARKER-GIFTCARDS\n", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: SaleService hooks + metadata passthrough')

# TenantSaleItem: fillable + cast
p2 = 'app/Models/Tenant/TenantSaleItem.php'
s2 = io.open(p2, encoding='utf-8').read()
a5 = "'service_id', 'inventory_item_id', 'gift_card_id',"
assert s2.count(a5) == 1
s2 = s2.replace(a5, a5 + " 'metadata', // MARKER-GIFTCARDS", 1)
if 'protected $casts' in s2:
    import re
    m = re.search(r'protected \$casts\s*=\s*\[\n', s2)
    assert m, 'casts array not found'
    s2 = s2[:m.end()] + "        'metadata' => 'array', // MARKER-GIFTCARDS\n" + s2[m.end():]
else:
    raise AssertionError('no casts array in TenantSaleItem')
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: TenantSaleItem metadata fillable + cast')
PY

# ---------------------------------------------------------------
# 6. RegisterController — tender list, validations, lookup, refund guard
# ---------------------------------------------------------------
python3 - <<'PY'
import io

p = 'app/Http/Controllers/Tenant/RegisterController.php'
src = io.open(p, encoding='utf-8').read()
assert 'MARKER-GIFTCARDS' not in src

# gift_card joins the base tender list
a1 = "['cash', 'card', 'check', 'store_credit', 'mark_paid', 'split']"
assert src.count(a1) == 1
src = src.replace(a1, "['cash', 'card', 'check', 'store_credit', 'gift_card', 'mark_paid', 'split']", 1)

# gift fields on every items validation block (4 occurrences)
a2 = "            'items.*.is_taxable'       => 'nullable|boolean',\n"
n = src.count(a2)
assert n == 4, f'is_taxable blocks: {n}'
src = src.replace(a2, a2 + """            // MARKER-GIFTCARDS -- gift line details, stored as line metadata
            'items.*.gift_card'                  => 'nullable|array',
            'items.*.gift_card.kind'             => 'nullable|string|in:physical,egift',
            'items.*.gift_card.code'             => 'nullable|string|max:40',
            'items.*.gift_card.recipient_name'   => 'nullable|string|max:120',
            'items.*.gift_card.recipient_email'  => 'nullable|email|max:160',
            'items.*.gift_card.gift_message'     => 'nullable|string|max:500',
""")

# storeTransaction: reject gift_card tender on mixed refund transactions
a3 = """    public function storeTransaction(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');
"""
assert src.count(a3) == 1
src = src.replace(a3, a3 + """
        // MARKER-GIFTCARDS -- gift-card tender is not wired into the mixed
        // sale+refund transaction path yet; rejecting loudly beats accepting
        // a tender that never debits the card. Plain sales support it fully.
        $pm = (string) $request->input('payment_method', '');
        $giftInSplit = collect((array) $request->input('payments', []))
            ->contains(fn ($p) => ($p['method'] ?? null) === 'gift_card');
        if ($pm === 'gift_card' || $giftInSplit) {
            return response()->json(['ok' => false, 'error' => 'Gift card tender isn\\'t available on transactions with refund lines yet — ring the sale separately.'], 422);
        }
""", 1)

# lookup endpoint method, appended before the final closing brace
i = src.rstrip().rfind('}')
methods = '''
    // MARKER-GIFTCARDS -- live balance check for the tender + sell modals.

    public function giftCardLookup(Request $request): JsonResponse
    {
        $code = (string) $request->query('code', '');
        $card = app(\\App\\Services\\Tenant\\GiftCardService::class)->lookup(tenant()->id, $code);

        if (! $card) {
            return response()->json(['ok' => false, 'error' => 'No gift card found for that code.'], 404);
        }

        return response()->json([
            'ok'            => true,
            'status'        => $card->status,
            'balance_cents' => (int) $card->balance_cents,
            'masked'        => $card->maskedCode(),
        ]);
    }
'''
src = src[:i] + methods + src[i:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: RegisterController tender list + validations + guard + lookup')

# route for the lookup, inside the retail group
p2 = 'routes/web.php'
s2 = io.open(p2, encoding='utf-8').read()
a4 = "Route::get('/register/lookup-sale',       [TenantControllers\\RegisterController::class, 'lookupSaleForRefund'])->name('register.lookup-sale');"
assert s2.count(a4) == 1
s2 = s2.replace(a4, a4 + "\n                Route::get('/register/gift-cards/lookup', [TenantControllers\\RegisterController::class, 'giftCardLookup'])->name('register.gift-cards.lookup'); // MARKER-GIFTCARDS", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: lookup route added')
PY

# ---------------------------------------------------------------
# 7. Register view — sell button + modal, tender button + code row, JS
# ---------------------------------------------------------------
python3 - <<'PY'
import io

p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()
assert 'MARKER-GIFTCARDS' not in src

# 7a. sell button next to "+ Add custom item"
a1 = '<button type="button" class="reg-open-item" id="addOpenItemBtn">+ Add custom item</button>'
assert src.count(a1) == 1
src = src.replace(a1, a1 + '\n      <button type="button" class="reg-open-item" id="sellGiftCardBtn">+ Sell gift card</button> {{-- MARKER-GIFTCARDS --}}', 1)

# 7b. tender button after Store credit
a2 = '<button type="button" class="reg-tender-btn" data-tender="store_credit">Store credit</button>'
assert src.count(a2) == 1
src = src.replace(a2, a2 + '\n      <button type="button" class="reg-tender-btn" data-tender="gift_card">Gift card</button> {{-- MARKER-GIFTCARDS --}}', 1)

# 7c. gift-card code row inside the tender modal, before the split amount row.
#     Anchor on the reference row, which every tender modal build has.
a3 = src.find('id="tenderRefRow"')
assert a3 > 0
row_start = src.rfind('<div', 0, a3)
gift_row = '''{{-- MARKER-GIFTCARDS -- code entry + live balance for the gift tender --}}
    <div id="gcTenderRow" style="display:none;margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Gift card code</label>
      <div style="display:flex;gap:8px">
        <input type="text" id="gcTenderCode" placeholder="GC-0000-0000-0000" style="flex:1;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:var(--ia-font-mono)">
        <button type="button" class="reg-btn-secondary" id="gcTenderCheckBtn" style="flex:0 0 auto;padding:10px 16px">Check</button>
      </div>
      <div id="gcTenderBalance" style="display:none;justify-content:space-between;align-items:center;border:1px solid var(--ia-border);border-radius:10px;padding:10px 14px;margin-top:10px;font-size:13px">
        <span>Balance on this card</span>
        <b id="gcTenderBalanceAmt" style="font-variant-numeric:tabular-nums;color:var(--ia-accent);font-size:15px"></b>
      </div>
      <div id="gcTenderErr" style="display:none;font-size:12.5px;color:#f87171;margin-top:8px"></div>
    </div>
    '''
src = src[:row_start] + gift_row + src[row_start:]

# 7d. sell modal, after the open-item modal
a4 = '''      <button type="button" class="reg-btn-primary" id="openItemAddBtn">Add to cart</button>
    </div>
  </div>
</div>
'''
assert src.count(a4) == 1
sell_modal = a4 + '''
{{-- MARKER-GIFTCARDS -- sell a gift card. Card is issued & activated when the
     sale COMPLETES, not when the line is added. --}}
<div class="reg-modal-bg" id="gcSellModal">
  <div class="reg-modal">
    <h2>Sell a gift card</h2>
    <div class="lede">Card is issued and activated when this sale is completed.</div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
      <button type="button" class="reg-tender-btn selected" id="gcKindPhysical">Physical card<br><span style="font-size:11.5px;color:var(--ia-text-dim);font-weight:400">Scan or type the card code</span></button>
      <button type="button" class="reg-tender-btn" id="gcKindEgift">E-gift card<br><span style="font-size:11.5px;color:var(--ia-text-dim);font-weight:400">Emailed to the recipient</span></button>
    </div>

    <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Amount</label>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px" id="gcAmountGrid">
      <button type="button" class="reg-tender-btn" data-cents="2500" style="text-align:center;font-weight:600">$25</button>
      <button type="button" class="reg-tender-btn" data-cents="5000" style="text-align:center;font-weight:600">$50</button>
      <button type="button" class="reg-tender-btn" data-cents="10000" style="text-align:center;font-weight:600">$100</button>
      <button type="button" class="reg-tender-btn" data-cents="15000" style="text-align:center;font-weight:600">$150</button>
    </div>
    <input type="text" id="gcCustomAmount" placeholder="Custom amount" inputmode="decimal">

    <div id="gcPhysicalFields">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Card code</label>
      <input type="text" id="gcSellCode" placeholder="Scan or type the printed code" style="font-family:var(--ia-font-mono)">
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Scan the barcode on the physical card, or type its printed code. Codes must be unused.</div>
    </div>

    <div id="gcEgiftFields" style="display:none">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Recipient email</label>
      <input type="email" id="gcSellEmail" placeholder="who@example.com">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Gift message <span style="font-weight:400;color:var(--ia-text-dim)">(optional)</span></label>
      <textarea id="gcSellMessage" maxlength="500"></textarea>
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Code is generated automatically and emailed when the sale completes.</div>
    </div>

    <div id="gcSellErr" style="display:none;font-size:12.5px;color:#f87171;margin-top:10px"></div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="gcSellModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="gcSellAddBtn">Add to sale</button>
    </div>
  </div>
</div>
'''
src = src.replace(a4, sell_modal, 1)

# 7e. serializeLine gains the gift passthrough
a5 = """  if (i.type === 'open_item') {
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
  }
  return out;
}

function showError(msg) {"""
assert src.count(a5) == 1
src = src.replace(a5, """  if (i.type === 'open_item') {
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
  }
  if (i.type === 'gift_card') { // MARKER-GIFTCARDS
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
    out.gift_card = i.gift || {};
  }
  return out;
}

function showError(msg) {""", 1)

# 7f. gift guards in the split-add handler: require a checked card, cap at
#     balance, and stamp the code as the payment reference.
a6 = """  const selBtn = document.querySelector('#tenderModal .reg-tender-btn.selected');
  cart.payments.push({
    method: cart.payment_method,
    amount_cents: c,
    change_cents: change,
    reference: (document.getElementById('tenderRefInput').value || '').trim() || null,"""
assert src.count(a6) == 1
src = src.replace(a6, """  // MARKER-GIFTCARDS -- gift leg needs a checked card; cap at its balance
  if (cart.payment_method === 'gift_card') {
    if (!window.gcTender || !gcTender.code) { showError('Check the gift card first.'); return; }
    if (c > gcTender.balance) c = gcTender.balance;
    if (c <= 0) return;
  }
  const selBtn = document.querySelector('#tenderModal .reg-tender-btn.selected');
  cart.payments.push({
    method: cart.payment_method,
    amount_cents: c,
    change_cents: change,
    reference: cart.payment_method === 'gift_card' ? gcTender.code
             : ((document.getElementById('tenderRefInput').value || '').trim() || null),""", 1)

# 7g. single-tender confirm guard: full total must fit the checked balance
a7 = "  cart.payment_reference = document.getElementById('tenderRefInput').value.trim() || null;\n"
assert src.count(a7) == 1
src = src.replace(a7, a7 + """
  // MARKER-GIFTCARDS -- single gift tender: require a checked card whose
  // balance covers the full total; otherwise it belongs in a split.
  if (cart.payment_method === 'gift_card' && cart.payments.length === 0) {
    if (!window.gcTender || !gcTender.code) { showError('Check the gift card balance first.'); return; }
    const gcDue = (calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents) - (calcRefundSubtotal() + calcRefundTax());
    if (gcTender.balance < gcDue) {
      showError('Gift card covers ' + fmt(gcTender.balance) + ' of ' + fmt(gcDue) + ' — add it as a split payment for the part it covers.');
      return;
    }
    cart.payment_reference = gcTender.code;
  }
""", 1)

# 7h. tender click handler: show/hide the gift row (piggyback the manual-row block)
a8 = """    } else {
      manualRow.style.display = 'none';
    }
    renderTotals();
  });
});"""
assert src.count(a8) == 1
src = src.replace(a8, """    } else {
      manualRow.style.display = 'none';
    }
    // MARKER-GIFTCARDS -- gift tender: code row + block on refund carts
    (function () {
      const isGift = btn.dataset.tender === 'gift_card';
      const gr = document.getElementById('gcTenderRow');
      if (gr) gr.style.display = isGift ? '' : 'none';
      if (isGift && cart.refund_lines.length > 0) {
        showError('Gift card tender isn\\'t available on transactions with refund lines yet — ring the sale separately.');
      }
      if (!isGift) { window.gcTender = null; const b = document.getElementById('gcTenderBalance'); if (b) b.style.display = 'none'; }
    })();
    renderTotals();
  });
});""", 1)

# 7i. all new JS: sell modal + balance check, appended to the main script.
#     Anchor on the open-item add handler and insert after its closing line.
a9 = """document.getElementById('openItemAddBtn').addEventListener('click', () => {
  const name = document.getElementById('openItemName').value.trim();
  const priceStr = document.getElementById('openItemPrice').value.trim();
  const priceFloat = parseFloat(priceStr);
  if (!name || isNaN(priceFloat) || priceFloat < 0) return;
  const cents = Math.round(priceFloat * 100);
  addToCart({type:'open_item', source_id:null, name, price_cents:cents, is_taxable:true});
  closeModal('openItemModal');
});
"""
assert src.count(a9) == 1
gift_js = a9 + """
// MARKER-GIFTCARDS -- sell-modal + tender balance check --------------------
window.gcTender = null;
const gcSell = { kind: 'physical', cents: null };

document.getElementById('sellGiftCardBtn').addEventListener('click', () => {
  gcSell.kind = 'physical'; gcSell.cents = null;
  document.getElementById('gcKindPhysical').classList.add('selected');
  document.getElementById('gcKindEgift').classList.remove('selected');
  document.getElementById('gcPhysicalFields').style.display = '';
  document.getElementById('gcEgiftFields').style.display = 'none';
  document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  ['gcCustomAmount','gcSellCode','gcSellEmail','gcSellMessage'].forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('gcSellErr').style.display = 'none';
  openModal('gcSellModal');
});

function gcSetKind(kind) {
  gcSell.kind = kind;
  document.getElementById('gcKindPhysical').classList.toggle('selected', kind === 'physical');
  document.getElementById('gcKindEgift').classList.toggle('selected', kind === 'egift');
  document.getElementById('gcPhysicalFields').style.display = kind === 'physical' ? '' : 'none';
  document.getElementById('gcEgiftFields').style.display = kind === 'egift' ? '' : 'none';
}
document.getElementById('gcKindPhysical').addEventListener('click', () => gcSetKind('physical'));
document.getElementById('gcKindEgift').addEventListener('click', () => gcSetKind('egift'));

document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(x => x.classList.remove('selected'));
    b.classList.add('selected');
    gcSell.cents = parseInt(b.dataset.cents, 10);
    document.getElementById('gcCustomAmount').value = '';
  });
});
document.getElementById('gcCustomAmount').addEventListener('input', () => {
  document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(x => x.classList.remove('selected'));
  gcSell.cents = null;
});

function gcSellError(msg) {
  const el = document.getElementById('gcSellErr');
  el.textContent = msg; el.style.display = '';
}

document.getElementById('gcSellAddBtn').addEventListener('click', async () => {
  document.getElementById('gcSellErr').style.display = 'none';
  let cents = gcSell.cents;
  const custom = document.getElementById('gcCustomAmount').value.trim();
  if (!cents && custom) {
    const f = parseFloat(custom.replace(/[^0-9.]/g, ''));
    if (!isNaN(f) && f > 0) cents = Math.round(f * 100);
  }
  if (!cents || cents < 100) { gcSellError('Pick or enter an amount of at least $1.00.'); return; }

  const gift = { kind: gcSell.kind };
  let label;
  if (gcSell.kind === 'physical') {
    const code = document.getElementById('gcSellCode').value.trim();
    if (!code) { gcSellError('Scan or type the card code.'); return; }
    // Reject a code already in use before it can poison the commit.
    try {
      const r = await fetch(ROUTES.giftCardLookup + '?code=' + encodeURIComponent(code), { headers: { 'Accept': 'application/json' } });
      if (r.ok) { gcSellError('That card code is already in use.'); return; }
    } catch (e) { /* offline: server re-checks at commit */ }
    gift.code = code;
    label = 'Gift card \\u00b7 ' + code.slice(-4);
  } else {
    const email = document.getElementById('gcSellEmail').value.trim();
    if (!email || !email.includes('@')) { gcSellError('Recipient email is required for an e-gift card.'); return; }
    gift.recipient_email = email;
    const msg = document.getElementById('gcSellMessage').value.trim();
    if (msg) gift.gift_message = msg;
    label = 'E-gift card \\u00b7 ' + email;
  }

  const line = {type:'gift_card', source_id:null, name:label, price_cents:cents, is_taxable:false};
  addToCart(line);
  cart.items[cart.items.length - 1].gift = gift;
  queueDraftSave();
  closeModal('gcSellModal');
});

document.getElementById('gcTenderCheckBtn').addEventListener('click', async () => {
  const code = document.getElementById('gcTenderCode').value.trim();
  const err = document.getElementById('gcTenderErr');
  const bal = document.getElementById('gcTenderBalance');
  err.style.display = 'none'; bal.style.display = 'none';
  window.gcTender = null;
  if (!code) return;
  try {
    const r = await fetch(ROUTES.giftCardLookup + '?code=' + encodeURIComponent(code), { headers: { 'Accept': 'application/json' } });
    const data = await r.json();
    if (!r.ok || !data.ok) { err.textContent = (data && data.error) || 'No gift card found for that code.'; err.style.display = ''; return; }
    if (data.status !== 'active') { err.textContent = 'Card ' + data.masked + ' is ' + data.status + '.'; err.style.display = ''; return; }
    window.gcTender = { code: code, balance: data.balance_cents };
    document.getElementById('gcTenderBalanceAmt').textContent = fmt(data.balance_cents);
    bal.style.display = 'flex';
    const inp = document.getElementById('splitAmountInput');
    if (inp) { inp.value = (Math.min(data.balance_cents, splitRemaining()) / 100).toFixed(2); }
  } catch (e) {
    err.textContent = 'Could not check the card — network error.'; err.style.display = '';
  }
});
// MARKER-GIFTCARDS end ------------------------------------------------------
"""
src = src.replace(a9, gift_js, 1)

# 7j. ROUTES map: add the lookup URL next to an existing entry
import re
m = re.search(r"const ROUTES = \{\n", src)
assert m, 'ROUTES object not found'
src = src[:m.end()] + "  giftCardLookup: '{{ route('tenant.register.gift-cards.lookup') }}', // MARKER-GIFTCARDS\n" + src[m.end():]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: register view — sell modal, tender row, JS, ROUTES entry')
PY

echo ""
echo "== gift-cards core applied =="
echo "Post-deploy: migrate runs in deploy; then php artisan optimize:clear + queue:restart"
