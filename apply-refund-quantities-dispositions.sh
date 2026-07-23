#!/bin/bash
# refund-quantities-dispositions — inventory audit finding #2.
#   THE BUG: the cashier picked a quantity, the payload discarded it, and the
#   backend refunded AND RESTOCKED the entire original line — one tube
#   returned put five back on the shelf.
#   NOW: refund.items[{sale_item_id, quantity, disposition}] is sent and the
#   SERVER is authoritative. Inside a lock on the original sale it sums every
#   prior refund of that line (legacy rows attributed by exact type/item/name
#   match), rejects anything over the remainder, and refunds + restocks only
#   the requested quantity. Money is prorated so discount and tax come back in
#   the same proportion as the goods. So: buy 5 Sunday, return 1 Saturday,
#   return 2 the next Sunday — each its own refund, 2 still refundable after.
#   DISPOSITIONS per line: restock / open_box return goods to sellable stock;
#   damaged / defective / warranty_hold / return_vendor / scrap record where
#   the item went WITHOUT a stock increment (Intake has no non-sellable bins
#   or RMA queue yet — the data is there for that workflow later);
#   customer_keeps moves no stock at all. Services offer no disposition.
#   original_sale_item_id links each refund line to the exact original line,
#   so renames can no longer break refund accounting, and 'refunded' vs
#   'partial' now reflects true per-line completion.
#   Legacy refund.item_ids still accepted (= full remaining, restocked).
# Server: MIGRATION REQUIRED + view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-REFUND-QTY" app/Services/Tenant/SaleService.php; then
  echo "refund-quantities already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TRANSFER-SCOPE" app/Http/Controllers/Tenant/RegisterController.php; then
  echo "wrong base (transfer-scope fix missing) — aborting."; exit 1
fi
mkdir -p database/migrations

cat > 'database/migrations/2026_07_22_000001_add_refund_line_tracking_to_tenant_sale_items.php' <<'REFQTY_0_EOF'
<?php

// MARKER-REFUND-QTY — refund lines now link to the exact original sale line
// (instead of being matched by name) and record where the returned goods
// went. Both nullable: legacy refund rows keep working, and the service
// falls back to best-effort matching when original_sale_item_id is absent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $t) {
            $t->uuid('original_sale_item_id')->nullable()->index();
            $t->string('disposition', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $t) {
            $t->dropColumn(['original_sale_item_id', 'disposition']);
        });
    }
};
REFQTY_0_EOF

cat > 'app/Models/Tenant/TenantSaleItem.php' <<'REFQTY_1_EOF'
<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantSaleItem extends Model
{
    use HasUuids;

    protected $table = 'tenant_sale_items';

    protected $fillable = [
        'tenant_id', 'sale_id', 'type',
        'service_id', 'inventory_item_id', 'gift_card_id',
        'name_snapshot', 'description_snapshot', 'cost_cents_snapshot',
        'quantity', 'unit_price_cents', 'discount_cents',
        'tax_rate_snapshot', 'is_taxable', 'tax_cents',
        'tip_cents', 'line_total_cents',
        'assigned_staff_id', 'position', 'notes',
        'original_sale_item_id', 'disposition', // MARKER-REFUND-QTY
    ];

    protected $casts = [
        'quantity'            => 'decimal:3',
        'unit_price_cents'    => 'integer',
        'discount_cents'      => 'integer',
        'tax_rate_snapshot'   => 'decimal:3',
        'is_taxable'          => 'boolean',
        'tax_cents'           => 'integer',
        'tip_cents'           => 'integer',
        'line_total_cents'    => 'integer',
        'cost_cents_snapshot' => 'integer',
        'position'            => 'integer',
    ];

    public function tenant(): BelongsTo        { return $this->belongsTo(Tenant::class); }
    public function sale(): BelongsTo          { return $this->belongsTo(TenantSale::class, 'sale_id'); }
    public function service(): BelongsTo       { return $this->belongsTo(TenantServiceItem::class, 'service_id'); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id'); }
    public function assignedStaff(): BelongsTo { return $this->belongsTo(TenantUser::class, 'assigned_staff_id'); }

    public function scopeOfType($q, string $type)  { return $q->where('type', $type); }
    public function scopeServices($q)              { return $q->where('type', 'service'); }
    public function scopeProducts($q)              { return $q->where('type', 'product'); }

    public function isService(): bool   { return $this->type === 'service'; }
    public function isProduct(): bool   { return $this->type === 'product'; }
    public function isOpenItem(): bool  { return $this->type === 'open_item'; }
    public function isGiftCard(): bool  { return $this->type === 'gift_card'; }
}
REFQTY_1_EOF

cat > 'app/Services/Tenant/SaleService.php' <<'REFQTY_2_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Models\Tenant\TenantSaleCounter;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SaleService
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    /**
     * Generate the next sale_number for a tenant on a given date.
     * Uses row-level lock-and-increment on tenant_sale_counters to prevent
     * collision under concurrent register writes.
     *
     * Format: S-YYYYMMDD-### (zero-padded sequence).
     */
    public function nextSaleNumber(string $tenantId, ?string $saleDate = null): string
    {
        // MARKER-PATCH-159 — tenant-local "today" for date-only sale_number generation
        $tenant = \App\Models\Tenant::find($tenantId);
        $tz = $tenant ? $tenant->timezone() : 'UTC';
        $date = $saleDate ? Carbon::parse($saleDate, $tz) : Carbon::today($tz);
        $datePart = $date->format('Ymd');

        return DB::transaction(function () use ($tenantId, $date, $datePart) {
            $counter = TenantSaleCounter::where('tenant_id', $tenantId)
                ->whereDate('counter_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = TenantSaleCounter::create([
                    'tenant_id'    => $tenantId,
                    'counter_date' => $date->toDateString(),
                    'last_seq'     => 0,
                ]);
            }

            $counter->increment('last_seq');
            $seq = str_pad((string) $counter->last_seq, 3, '0', STR_PAD_LEFT);

            return "S-{$datePart}-{$seq}";
        });
    }

    /**
     * Create a sale and its line items atomically.
     */
    public function createSale(array $data): TenantSale
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        // Customer-required-for-service validation
        $hasServiceLine = collect($items)->contains(fn ($i) => ($i['type'] ?? null) === 'service');
        if ($hasServiceLine && empty($data['customer_id'])) {
            throw new SaleValidationException(
                'Customer is required when the sale has any service line.'
            );
        }

        // Location is required (DB allows null for legacy/imports; app enforces presence).
        if (empty($data['location_id'])) {
            throw new SaleValidationException(
                'location_id is required to create a sale.'
            );
        }

        return DB::transaction(function () use ($data, $items) {
            $tenantId = $data['tenant_id'];
            // MARKER-PATCH-159 — tenant-local today, not UTC
            $saleDate = $data['sale_date'] ?? \App\Models\Tenant::find($data['tenant_id'])?->localToday()->toDateString() ?? Carbon::today()->toDateString();

            $sale = TenantSale::create([
                'tenant_id'          => $tenantId,
                'sale_number'        => $this->nextSaleNumber($tenantId, $saleDate),
                'sale_date'          => $saleDate,
                'status'             => $data['status'] ?? 'pending',
                'payment_status'     => $data['payment_status'] ?? 'unpaid',
                'customer_id'        => $data['customer_id'] ?? null,
                'assigned_staff_id'  => $data['assigned_staff_id'] ?? null,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                'location_id'        => $data['location_id'],
                'register_id'        => $data['register_id'] ?? null, // MARKER-REGISTER-RECON-DISPLAY
                'client_uuid'        => $data['client_uuid'] ?? null, // MARKER-OFFLINE-SYNC
                'payment_method'     => $data['payment_method'] ?? null,
                'payment_reference'  => $data['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments card metadata
                'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? null,
                'stripe_charge_id'         => $data['stripe_charge_id']         ?? null,
                'card_brand'               => $data['card_brand']               ?? null,
                'card_last4'               => $data['card_last4']               ?? null,
                'card_funding'             => $data['card_funding']             ?? null,
                // MARKER-PATCH-172E — Checkout Session ID for send-payment-link flow.
                // Missing this passthrough broke patch 172's draft-sale linkage.
                'checkout_session_id'      => $data['checkout_session_id']      ?? null,
                'paid_at'            => $data['paid_at'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'subtotal_cents'     => 0,
                'discount_cents'     => 0,
                'tax_cents'          => 0,
                'surcharge_cents'    => 0,
                'tip_cents'          => (int) ($data['tip_cents'] ?? 0),
                'total_cents'        => 0,
            ]);

            $position = 0;
            foreach ($items as $itemData) {
                $this->createSaleItem($sale, $itemData, $position++);
            }

            // Decrement inventory for product lines, in same transaction.
            // Throws InventoryStockException if stock insufficient and no allow_oversell.
            $sale->load('items');
            foreach ($sale->items as $line) {
                if ($line->type === 'product') {
                    $this->inventory->decrementForSaleItem($sale, $line, $data['location_id']);
                }
            }

            $finalSale = $this->recalculate($sale->fresh('items'));

            // MARKER-PATCH-176 — record payment on the SALE ledger (createSale
            // path). Mirrors the commit path; appointment reads it through its
            // sale. Refresh appointment paid_cents cache afterward.
            if ($finalSale->payment_status === 'paid') {
                try {
                    $existingOnSale = $finalSale->payments()->count();
                    if ($existingOnSale === 0) {
                        $kind = $finalSale->appointment_id
                            ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                            : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT;
                    } else {
                        $kind = \App\Models\Tenant\TenantSalePayment::KIND_BALANCE;
                    }

                    $this->recordRegisterPayments($finalSale, $data, $kind); // MARKER-SPLIT-TENDER

                    // MARKER-PATCH-219C — appointment paid cache cascades
                    // centrally in SalePaymentService::recalcStatus().
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Sale payment ledger write failed (createSale)', [
                        'sale_id'        => $finalSale->id,
                        'appointment_id' => $finalSale->appointment_id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }

            return $finalSale;
        });
    }

    /**
     * Save a cart as a draft. Like createSale but:
     *   - skips inventory decrement
     *   - skips customer-required-for-service validation
     *   - leaves sale_number null
     *   - sets payment_status to 'draft'
     *
     * If $data contains 'id', updates that draft (nuke-and-rebuild items).
     * Otherwise creates a new draft.
     *
     * Required: tenant_id, location_id, rang_up_by_user_id.
     */
    public function saveDraft(array $data): TenantSale
    {
        if (empty($data['location_id'])) {
            throw new SaleValidationException(
                'location_id is required to save a draft.'
            );
        }

        $items = $data['items'] ?? [];
        unset($data['items']);

        return DB::transaction(function () use ($data, $items) {
            $existingId = $data['id'] ?? null;

            if ($existingId) {
                $draft = TenantSale::where('id', $existingId)
                    ->where('tenant_id', $data['tenant_id'])
                    ->where('payment_status', 'draft')
                    ->lockForUpdate()
                    ->first();

                if (!$draft) {
                    throw new SaleValidationException('Draft not found.');
                }

                $draft->update([
                    'customer_id'        => $data['customer_id']        ?? $draft->customer_id,
                    'assigned_staff_id'  => $data['assigned_staff_id']  ?? $draft->assigned_staff_id,
                    'appointment_id'     => $data['appointment_id']     ?? $draft->appointment_id,
                    'location_id'        => $data['location_id'],
                    'tip_cents'          => (int) ($data['tip_cents'] ?? $draft->tip_cents),
                    'notes'              => $data['notes']              ?? $draft->notes,
                    'metadata'           => $data['metadata']           ?? $draft->metadata,
                ]);

                // Nuke-and-rebuild items. Drafts are transient; IDs don't matter.
                $draft->items()->delete();
            } else {
                // MARKER-PATCH-159 — tenant-local today, not UTC
                $saleDate = $data['sale_date'] ?? \App\Models\Tenant::find($data['tenant_id'])?->localToday()->toDateString() ?? Carbon::today()->toDateString();
                $draft = TenantSale::create([
                    'tenant_id'          => $data['tenant_id'],
                    'sale_number'        => null,
                    'sale_date'          => $saleDate,
                    'status'             => 'pending',
                    'payment_status'     => 'draft',
                    'customer_id'        => $data['customer_id']        ?? null,
                    'assigned_staff_id'  => $data['assigned_staff_id']  ?? null,
                    'appointment_id'     => $data['appointment_id']     ?? null,
                    'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                    'location_id'        => $data['location_id'],
                    'notes'              => $data['notes'] ?? null,
                    'metadata'           => $data['metadata'] ?? null,
                    'subtotal_cents'     => 0,
                    'discount_cents'     => 0,
                    'tax_cents'          => 0,
                    'surcharge_cents'    => 0,
                    'tip_cents'          => (int) ($data['tip_cents'] ?? 0),
                    'total_cents'        => 0,
                ]);
            }

            $position = 0;
            foreach ($items as $itemData) {
                $this->createSaleItem($draft, $itemData, $position++);
            }

            return $this->recalculate($draft->fresh('items'));
        });
    }

    /**
     * Save a cart as a quote. Like saveDraft but:
     *   - requires customer_id
     *   - requires quote_expires_at
     *   - sets payment_status to 'quote'
     *
     * Quotes are tenant-wide (not location-scoped for listing purposes), but
     * are stamped with a location_id like any sale row.
     */
    public function saveQuote(array $data): TenantSale
    {
        if (empty($data['customer_id'])) {
            throw new SaleValidationException(
                'A customer is required to save a quote.'
            );
        }

        // Reuse draft path, then flip status. Expiry is opt-in.
        $sale = $this->saveDraft($data);

        $sale->update([
            'payment_status'   => 'quote',
            'quote_expires_at' => $data['quote_expires_at'] ?? null,
        ]);

        return $sale->fresh('items');
    }

    /**
     * Permanently delete a draft (or quote) and its items.
     * Caller is responsible for tenant scoping; we double-check here.
     */
    public function discardDraft(string $tenantId, string $saleId): void
    {
        $sale = TenantSale::where('id', $saleId)
            ->where('tenant_id', $tenantId)
            ->whereIn('payment_status', ['draft', 'quote'])
            ->first();

        if (!$sale) {
            throw new SaleValidationException('Draft not found or not discardable.');
        }

        DB::transaction(function () use ($sale) {
            $sale->items()->delete();
            $sale->delete();
        });
    }

    /**
     * Promote a draft (or quote) into a committed sale.
     * Assigns sale_number, runs inventory decrement, flips payment_status.
     *
     * $data may include: payment_status (default 'paid'), payment_method,
     * payment_reference, paid_at, tip_cents, notes, customer_id.
     */
    public function commitDraft(string $tenantId, string $saleId, array $data): TenantSale
    {
        return DB::transaction(function () use ($tenantId, $saleId, $data) {
            $sale = TenantSale::where('id', $saleId)
                ->where('tenant_id', $tenantId)
                ->whereIn('payment_status', ['draft', 'quote'])
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$sale) {
                throw new SaleValidationException('Draft not found or already committed.');
            }

            // Customer-required-for-service is enforced HERE, at commit, not at draft save.
            $hasServiceLine = $sale->items->contains(fn ($i) => $i->type === 'service');
            $customerId = $data['customer_id'] ?? $sale->customer_id;
            if ($hasServiceLine && empty($customerId)) {
                throw new SaleValidationException(
                    'Customer is required when the sale has any service line.'
                );
            }

            $newPaymentStatus = $data['payment_status'] ?? 'paid';
            $paidAt = $newPaymentStatus === 'paid'
                ? ($data['paid_at'] ?? Carbon::now())
                : null;

            // If this row was a quote and is now becoming paid, stamp was_quote=true
            // so the dashboard's 'Recently converted' card can find it.
            $wasQuote = $sale->payment_status === 'quote' && $newPaymentStatus === 'paid';

            $sale->update([
                'sale_number'       => $this->nextSaleNumber($tenantId, $sale->sale_date),
                'payment_status'    => $newPaymentStatus,
                'customer_id'       => $customerId,
                'payment_method'    => $data['payment_method']    ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments card metadata
                'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? null,
                'stripe_charge_id'         => $data['stripe_charge_id']         ?? null,
                'card_brand'               => $data['card_brand']               ?? null,
                'card_last4'               => $data['card_last4']               ?? null,
                'card_funding'             => $data['card_funding']             ?? null,
                // MARKER-PATCH-172E — Checkout Session ID for send-payment-link flow.
                // Missing this passthrough broke patch 172's draft-sale linkage.
                'checkout_session_id'      => $data['checkout_session_id']      ?? null,
                'paid_at'           => $paidAt,
                'tip_cents'         => (int) ($data['tip_cents'] ?? $sale->tip_cents),
                'notes'             => $data['notes'] ?? $sale->notes,
                'quote_expires_at'  => null,
                'was_quote'         => $wasQuote ?: $sale->was_quote,
            ]);

            // Decrement inventory for product lines now that we're committing.
            foreach ($sale->items as $line) {
                if ($line->type === 'product') {
                    $this->inventory->decrementForSaleItem($sale, $line, $sale->location_id);
                }
            }

            $finalSale = $this->recalculate($sale->fresh('items'));

            // Appointment-payment hook. If this sale is linked to an
            // appointment (auto-created from Completed, or manual deposit
            // collection), write a payment row to the appointment ledger.
            //
            // The kind is determined by whether the appointment has any
            // existing payments — first payment ever = 'deposit', else
            // 'balance'. The bridge service handles the recompute of
            // appointment.paid_cents and payment_status.
            //
            // Wrapped to never roll back the sale on failure: if the
            // ledger write fails for some reason, the sale is still real
            // money and committed, and an admin can manually record the
            // payment row later. Logging surfaces the issue.
            // MARKER-PATCH-176 — record the payment on the SALE ledger
            // (sales-as-money). Every paid sale gets a payment row here; if the
            // sale is appointment-linked, the appointment reads it THROUGH its
            // sale (see TenantAppointment::payments()). We then refresh the
            // appointment's paid_cents cache so its detail banner stays correct.
            //
            // Kind: first payment on THIS sale = deposit (appointment job) or
            // payment (walk-in retail); subsequent payments = balance.
            //
            // Wrapped so a ledger failure never rolls back the sale — the sale
            // is real money and committed; an admin can record the row later.
            if ($newPaymentStatus === 'paid') {
                try {
                    $existingOnSale = $finalSale->payments()->count();
                    if ($existingOnSale === 0) {
                        $kind = $finalSale->appointment_id
                            ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                            : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT;
                    } else {
                        $kind = \App\Models\Tenant\TenantSalePayment::KIND_BALANCE;
                    }

                    $this->recordRegisterPayments($finalSale, $data, $kind); // MARKER-SPLIT-TENDER

                    // MARKER-PATCH-219C — appointment paid cache cascades
                    // centrally in SalePaymentService::recalcStatus().
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Sale payment ledger write failed', [
                        'sale_id'        => $finalSale->id,
                        'appointment_id' => $finalSale->appointment_id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }

            // Class-registration hook. If this sale was opened from the
            // "register customer for class via cash" flow, the class_session_id
            // is stashed in metadata. On commit, we register the customer for
            // the class with payment_method=cash. Done after recalculate so
            // any failure here doesn't poison the sale state.
            //
            // Wrapped in try/catch so a class-registration failure (capacity
            // change between draft and commit, customer already registered,
            // etc.) doesn't undo the sale itself. The sale stands; the admin
            // gets a flash error and can register manually.
            $meta = $finalSale->metadata ?? [];
            if (!empty($meta['class_session_id']) && $finalSale->customer_id && $newPaymentStatus === 'paid') {
                try {
                    app(\App\Services\ClassRegistrationService::class)->register(
                        $meta['class_session_id'],
                        $finalSale->customer_id,
                        $tenantId,
                        'cash'
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('class registration after sale commit failed', [
                        'sale_id'          => $finalSale->id,
                        'class_session_id' => $meta['class_session_id'],
                        'customer_id'      => $finalSale->customer_id,
                        'error'            => $e->getMessage(),
                    ]);
                    // Re-throw inside the transaction so the calling controller
                    // can surface the message to the admin. The sale remains
                    // committed because we're outside the transaction at this
                    // point — wait, we ARE inside the transaction. Re-throwing
                    // would roll back the sale. We DON'T want that — the cash
                    // was taken, the sale is real. Better to log and let the
                    // admin reconcile.
                }
            }

            return $finalSale;
        });
    }

    /**
     * Create a single line item on a sale, snapshotting fields from source.
     */
    protected function createSaleItem(TenantSale $sale, array $data, int $position): TenantSaleItem
    {
        $type = $data['type'] ?? 'open_item';

        $name           = $data['name_snapshot'] ?? null;
        $description    = $data['description_snapshot'] ?? null;
        $unitPriceCents = $data['unit_price_cents'] ?? null;
        $costCents      = $data['cost_cents_snapshot'] ?? null;
        $isTaxable      = $data['is_taxable'] ?? true;
        $serviceId      = $data['service_id'] ?? null;
        $inventoryItemId= $data['inventory_item_id'] ?? null;
        $giftCardId     = $data['gift_card_id'] ?? null;

        // Snapshot from source records when available
        if ($type === 'service' && $serviceId) {
            $service = TenantServiceItem::find($serviceId);
            if ($service) {
                $name           = $name           ?? $service->name;
                $description    = $description    ?? $service->description;
                $unitPriceCents = $unitPriceCents ?? (int) ($service->price_cents ?? 0);
            }
        } elseif ($type === 'product' && $inventoryItemId) {
            $item = TenantInventoryItem::find($inventoryItemId);
            if ($item) {
                $name           = $name           ?? ($item->name ?? '');
                $description    = $description    ?? ($item->description ?? null);
                $unitPriceCents = $unitPriceCents ?? (int) ($item->effectiveSellPriceCents() ?? 0);
                $costCents      = $costCents      ?? (int) ($item->effectiveCostCents() ?? 0);
                // tax_class_code may be 'exempt'; default true otherwise
                $isTaxable      = $data['is_taxable'] ?? (($item->tax_class_code ?? null) !== 'exempt');
            }
        }

        if ($name === null || $name === '') {
            throw new SaleValidationException(
                'Sale item is missing a name_snapshot (required for type=' . $type . ').'
            );
        }
        if ($unitPriceCents === null) {
            throw new SaleValidationException(
                'Sale item is missing unit_price_cents (required for type=' . $type . ').'
            );
        }

        $quantity      = (float) ($data['quantity'] ?? 1);
        $discountCents = (int) ($data['discount_cents'] ?? 0);

        $grossCents     = (int) round($unitPriceCents * $quantity);
        $lineTotalCents = max(0, $grossCents - $discountCents);

        return TenantSaleItem::create([
            'tenant_id'           => $sale->tenant_id,
            'sale_id'             => $sale->id,
            'type'                => $type,
            'service_id'          => $serviceId,
            'inventory_item_id'   => $inventoryItemId,
            'gift_card_id'        => $giftCardId,
            'name_snapshot'       => $name,
            'description_snapshot'=> $description,
            'cost_cents_snapshot' => $costCents,
            'quantity'            => $quantity,
            'unit_price_cents'    => $unitPriceCents,
            'discount_cents'      => $discountCents,
            'tax_rate_snapshot'   => $data['tax_rate_snapshot'] ?? null,
            'is_taxable'          => $isTaxable,
            'tax_cents'           => (int) ($data['tax_cents'] ?? 0),
            'tip_cents'           => 0,
            'line_total_cents'    => $lineTotalCents,
            'assigned_staff_id'   => $data['assigned_staff_id'] ?? null,
            'position'            => $data['position'] ?? $position,
            'notes'               => $data['notes'] ?? null,
        ]);
    }

    /**
     * Recompute sale totals from its items + tenant settings.
     * Does NOT include surcharge — that is settlement-time logic.
     */
    public function recalculate(TenantSale $sale): TenantSale
    {
        $tenant = $sale->tenant;
        $taxLocked = (bool) ($sale->tax_locked ?? false);
        $taxRate = (float) ($tenant->default_tax_rate ?? 0);
        $taxServicesByDefault = (bool) ($tenant->tax_services_default ?? true);

        $subtotal = 0;
        $discount = 0;
        $tax      = 0;

        foreach ($sale->items as $item) {
            $subtotal += $item->line_total_cents;
            $discount += $item->discount_cents;

            // tax_locked sales (e.g. bridge-created from appointments) carry
            // pre-computed per-line tax that we must preserve, not recompute.
            if ($taxLocked) {
                $tax += (int) $item->tax_cents;
                continue;
            }

            $shouldTax = $item->is_taxable
                && ($item->type !== 'service' || $taxServicesByDefault);

            if ($shouldTax && $taxRate > 0) {
                $lineTax = (int) round($item->line_total_cents * ($taxRate / 100));
                if ($lineTax !== $item->tax_cents
                    || (string) $taxRate !== (string) $item->tax_rate_snapshot) {
                    $item->update([
                        'tax_cents'         => $lineTax,
                        'tax_rate_snapshot' => $taxRate,
                    ]);
                }
                $tax += $lineTax;
            } else {
                if ($item->tax_cents !== 0 || $item->tax_rate_snapshot !== null) {
                    $item->update([
                        'tax_cents'         => 0,
                        'tax_rate_snapshot' => null,
                    ]);
                }
            }
        }

        $total = $subtotal + $tax + $sale->tip_cents + $sale->surcharge_cents;

        $sale->update([
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents'      => $tax,
            'total_cents'    => $total,
        ]);

        return $sale->fresh('items');
    }

    /**
     * Create a refund row referencing an original sale.
     * Refunds are negative-effect sale rows: line items mirror the originals,
     * inventory is restocked via InventoryService::incrementForRefund(), and
     * the original's payment_status flips to 'refunded' (full) or 'partial'.
     *
     * Expected $data:
     *   tenant_id (required)
     *   original_sale_id (required)
     *   rang_up_by_user_id (required)
     *   refund_method (required) — cash | card | check | store_credit | mark_paid
     *   reason (optional)
     *   notes (optional)
     *   item_ids (required, array of original sale_item ids to refund)
     */
    /** MARKER-REFUND-QTY — where returned goods went. */
    public const DISPOSITION_DEFAULT = 'restock';
    public const DISPOSITIONS = [
        'restock', 'open_box', 'damaged', 'defective',
        'warranty_hold', 'return_vendor', 'scrap', 'customer_keeps',
    ];
    /** Dispositions that put goods back into sellable stock. */
    public const DISPOSITIONS_RESTOCK = ['restock', 'open_box'];

    public function createRefund(array $data): TenantSale
    {
        $original = TenantSale::where('id', $data['original_sale_id'] ?? null)
            ->where('tenant_id', $data['tenant_id'] ?? null)
            ->with('items')
            ->first();

        if (!$original) {
            throw new SaleValidationException('Original sale not found.');
        }
        if ($original->payment_status !== 'paid' && $original->payment_status !== 'partial') {
            throw new SaleValidationException('Only paid sales can be refunded.');
        }
        if ($original->refund_of_sale_id !== null) {
            throw new SaleValidationException('Cannot refund a refund row.');
        }
        // MARKER-REFUND-QTY — the payload is now authoritative per line:
        //   items: [{sale_item_id, quantity, disposition}]
        // Legacy item_ids is still accepted and read as "the full remaining
        // quantity, restocked" so nothing in flight breaks.
        $requested = [];
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $row) {
                $sid = $row['sale_item_id'] ?? null;
                if (!$sid) continue;
                $requested[$sid] = [
                    'quantity'    => isset($row['quantity']) && $row['quantity'] !== null ? (float) $row['quantity'] : null,
                    'disposition' => $row['disposition'] ?? self::DISPOSITION_DEFAULT,
                ];
            }
        } elseif (!empty($data['item_ids']) && is_array($data['item_ids'])) {
            foreach ($data['item_ids'] as $sid) {
                $requested[$sid] = ['quantity' => null, 'disposition' => self::DISPOSITION_DEFAULT];
            }
        }
        if (empty($requested)) {
            throw new SaleValidationException('No items selected to refund.');
        }
        foreach ($requested as $sid => $row) {
            if (!in_array($row['disposition'], self::DISPOSITIONS, true)) {
                throw new SaleValidationException('Unknown return disposition: ' . $row['disposition']);
            }
        }

        $itemsToRefund = $original->items->whereIn('id', array_keys($requested));
        if ($itemsToRefund->isEmpty()) {
            throw new SaleValidationException('No matching items to refund.');
        }
        // Resolve a location for the refund through a fallback chain:
        //   1. original sale's location_id
        //   2. original's appointment's location_id (if appointment-derived)
        //   3. tenant's default active location
        // Only error if NO location exists anywhere on the tenant.
        $refundLocationId = $original->location_id;
        if (! $refundLocationId && $original->appointment_id) {
            $refundLocationId = \App\Models\Tenant\TenantAppointment::where('id', $original->appointment_id)
                ->value('location_id');
        }
        if (! $refundLocationId) {
            $refundLocationId = \App\Models\Tenant\TenantLocation::query()
                ->where('tenant_id', $original->tenant_id)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');
        }
        if (! $refundLocationId) {
            throw new SaleValidationException('Tenant has no active location; cannot refund.');
        }

        return DB::transaction(function () use ($data, $original, $itemsToRefund, $refundLocationId, $requested) {
            $tenantId = $data['tenant_id'];

            // MARKER-REFUND-QTY — serialize concurrent refunds of the same
            // sale: two registers must not both spend the same remainder.
            TenantSale::whereKey($original->id)->lockForUpdate()->first();

            // Sum what each original line has ALREADY had refunded, across
            // every prior refund of this sale. Lines written before this patch
            // carry no original_sale_item_id, so they are attributed by an
            // exact type/item/name match (legacy refunds always took the whole
            // line, so that attribution is accurate).
            $priorRefundSaleIds = TenantSale::where('tenant_id', $tenantId)
                ->where('refund_of_sale_id', $original->id)
                ->pluck('id');
            $prior = [];
            if ($priorRefundSaleIds->isNotEmpty()) {
                $priorLines = TenantSaleItem::whereIn('sale_id', $priorRefundSaleIds)->get();
                foreach ($priorLines as $pl) {
                    $key = $pl->original_sale_item_id;
                    if (!$key) {
                        $key = $original->items->first(function ($o) use ($pl) {
                            return $o->type === $pl->type
                                && $o->inventory_item_id === $pl->inventory_item_id
                                && $o->service_id === $pl->service_id
                                && $o->name_snapshot === $pl->name_snapshot;
                        })?->id;
                    }
                    if ($key) {
                        $prior[$key] = ($prior[$key] ?? 0) + (float) $pl->quantity;
                    }
                }
            }
            // MARKER-PATCH-159 — tenant-local today for refund sale_date
            $today = \App\Models\Tenant::find($tenantId)?->localToday()->toDateString() ?? Carbon::today()->toDateString();
            $reason = trim((string) ($data['reason'] ?? ''));
            $notes  = trim((string) ($data['notes'] ?? ''));
            $combinedNotes = trim($reason . ($reason && $notes ? "\n" : '') . $notes);

            $refund = TenantSale::create([
                'tenant_id'          => $tenantId,
                'sale_number'        => $this->nextSaleNumber($tenantId, $today),
                'sale_date'          => $today,
                'status'             => 'completed',
                'payment_status'     => 'refunded',
                'customer_id'        => $original->customer_id,
                'assigned_staff_id'  => null,
                'appointment_id'     => null,
                'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                'refund_of_sale_id'  => $original->id,
                'location_id'        => $refundLocationId,
                'notes'              => $combinedNotes !== '' ? $combinedNotes : null,
                'subtotal_cents'     => 0,
                'discount_cents'     => 0,
                'tax_cents'          => 0,
                'surcharge_cents'    => 0,
                'tip_cents'          => 0,
                'total_cents'        => 0,
                'paid_at'            => Carbon::now(),
                'payment_method'     => $data['refund_method'],
            ]);

            $position = 0;
            $appliedNow = [];
            foreach ($itemsToRefund as $orig) {
                // MARKER-REFUND-QTY — the backend, not the browser, decides
                // how much may come back on this line.
                $origQty   = (float) $orig->quantity;
                $already   = (float) ($prior[$orig->id] ?? 0);
                $remaining = round($origQty - $already, 4);
                if ($remaining <= 0) {
                    throw new SaleValidationException(
                        sprintf('%s has already been fully refunded.', $orig->name_snapshot)
                    );
                }

                $qty = $requested[$orig->id]['quantity'];
                if ($qty === null) {
                    $qty = $remaining; // legacy payload: the whole remainder
                }
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $remaining + 0.0001) {
                    throw new SaleValidationException(sprintf(
                        'Cannot refund %s × %s — only %s of %s remain unrefunded.',
                        rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                        $orig->name_snapshot,
                        rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($origQty, 3, '.', ''), '0'), '.')
                    ));
                }

                // Money is prorated from the original line so discounts and
                // tax come back in the same proportion as the goods.
                $ratio = $origQty > 0 ? ($qty / $origQty) : 1.0;
                $dispo = $requested[$orig->id]['disposition'];

                $line = TenantSaleItem::create([
                    'tenant_id'           => $tenantId,
                    'sale_id'             => $refund->id,
                    'type'                => $orig->type,
                    'service_id'          => $orig->service_id,
                    'inventory_item_id'   => $orig->inventory_item_id,
                    'gift_card_id'        => $orig->gift_card_id,
                    'name_snapshot'       => $orig->name_snapshot,
                    'description_snapshot'=> $orig->description_snapshot,
                    'cost_cents_snapshot' => $orig->cost_cents_snapshot,
                    'quantity'            => $qty,
                    'unit_price_cents'    => $orig->unit_price_cents,
                    'discount_cents'      => (int) round(((int) $orig->discount_cents) * $ratio),
                    'tax_rate_snapshot'   => $orig->tax_rate_snapshot,
                    'is_taxable'          => $orig->is_taxable,
                    'tax_cents'           => (int) round(((int) $orig->tax_cents) * $ratio),
                    'tip_cents'           => 0,
                    'line_total_cents'    => (int) round(((int) $orig->line_total_cents) * $ratio),
                    'assigned_staff_id'   => null,
                    'position'            => $position++,
                    'notes'               => null,
                    'original_sale_item_id' => $orig->id,   // MARKER-REFUND-QTY
                    'disposition'           => $dispo,      // MARKER-REFUND-QTY
                ]);

                $appliedNow[$orig->id] = ($appliedNow[$orig->id] ?? 0) + $qty;

                // MARKER-REFUND-QTY — only sellable dispositions put goods
                // back on the shelf. The rest record where the item went; the
                // vendor-return / warranty workflows consume that data later.
                if ($line->type === 'product' && in_array($dispo, self::DISPOSITIONS_RESTOCK, true)) {
                    $this->inventory->incrementForRefund($refund, $line, $refundLocationId);
                }
            }

            if (empty($appliedNow)) {
                throw new SaleValidationException('No refundable quantity selected.');
            }

            // MARKER-REFUND-QTY — 'refunded' only when EVERY original line has
            // been fully returned across all refunds; otherwise 'partial'.
            $allRefunded = true;
            foreach ($original->items as $o) {
                $done = (float) ($prior[$o->id] ?? 0) + (float) ($appliedNow[$o->id] ?? 0);
                if (round($done, 4) + 0.0001 < round((float) $o->quantity, 4)) {
                    $allRefunded = false;
                    break;
                }
            }
            $original->update([
                'payment_status' => $allRefunded ? 'refunded' : 'partial',
            ]);

            $finalRefund = $this->recalculate($refund->fresh('items'));

            // Appointment-payment ledger hook for refunds.
            //
            // If the original sale was tied to an appointment, write a refund
            // ledger row against the most recent inbound payment on that
            // appointment. The refund is tied to the new refund-sale via
            // register_sale_id and to the original payment via reference_payment_id.
            //
            // Wrapped to never roll back the refund-sale on failure: the cash
            // movement is real even if the ledger write trips. Logged for
            // manual reconciliation.
            //
            // Phase 1 limitation: refunds against a single inbound payment.
            // If refund amount exceeds that one row, we log and bail rather
            // than half-implementing a cascade across deposit + balance.
            // MARKER-PATCH-176 — refund row on the SALE ledger. Identical-but-
            // repointed from the appointment ledger (the standalone/simplified
            // refund redesign is patch-177). We record a NEGATIVE row against
            // the ORIGINAL sale so its net paid drops and recalcStatus reflects
            // refunded/partial; the appointment (if any) reads it through the
            // sale. Phase-1 behavior preserved: refund against a single inbound
            // payment row; if it exceeds that row we log and skip the auto-write.
            try {
                $refundCents = (int) abs($finalRefund->total_cents);
                if ($refundCents > 0) {
                    $originalPayment = \App\Models\Tenant\TenantSalePayment::query()
                        ->where('sale_id', $original->id)
                        ->whereIn('kind', [
                            \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT,
                            \App\Models\Tenant\TenantSalePayment::KIND_BALANCE,
                            \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT,
                        ])
                        ->orderByDesc('recorded_at')
                        ->first();

                    if (! $originalPayment) {
                        \Illuminate\Support\Facades\Log::warning('Refund ledger: no original payment row found for sale', [
                            'refund_sale_id'   => $finalRefund->id,
                            'original_sale_id' => $original->id,
                        ]);
                    } elseif ($refundCents > $originalPayment->amount_cents) {
                        \Illuminate\Support\Facades\Log::warning('Refund ledger: refund exceeds single original payment, skipping auto-write', [
                            'refund_sale_id'      => $finalRefund->id,
                            'refund_cents'        => $refundCents,
                            'original_payment_id' => $originalPayment->id,
                            'original_amount'     => $originalPayment->amount_cents,
                        ]);
                    } else {
                        app(\App\Services\Tenant\SalePaymentService::class)->refund(
                            sale:               $original,
                            amountCents:        $refundCents,
                            method:             $data['refund_method'] ?? 'other',
                            referencePaymentId: $originalPayment->id,
                            notes:              "Refund via sale {$finalRefund->sale_number}",
                        );

                        // MARKER-PATCH-219C — appointment paid cache cascades
                        // centrally in SalePaymentService::recalcStatus().
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Sale refund ledger write failed', [
                    'refund_sale_id'   => $finalRefund->id,
                    'original_sale_id' => $original->id,
                    'error'            => $e->getMessage(),
                ]);
            }

            return $finalRefund;
        });
    }

    /**
     * Create a multi-row transaction (sale + optional refund of prior sale).
     *
     * Use cases:
     *   - Pure sale: $data['items'] has new lines, no $data['refund'] block. Behaves like createSale.
     *   - Pure refund: no $data['items'], $data['refund'] block has original_sale_id + item_ids. Behaves like createRefund.
     *   - Exchange: both — refund happens against original sale, new sale is rung up, both rows share transaction_id.
     *
     * Tender direction is the caller's responsibility. This method just composes
     * the two writes inside one DB transaction and stamps a shared transaction_id.
     *
     * Expected $data:
     *   tenant_id (required)
     *   rang_up_by_user_id (required)
     *   location_id (required)
     *   customer_id (optional)
     *   notes (optional)
     *   tip_cents (optional, applies to new-sale row only)
     *   payment_method (required if any net payment is moving)
     *   payment_reference (optional)
     *   items (optional array — new sale lines)
     *   refund (optional array):
     *     original_sale_id (required if refund present)
     *     item_ids (required if refund present)
     *     reason (optional)
     *     refund_method (required if refund present — cash/card/check/store_credit/mark_paid)
     *
     * Returns:
     *   array with keys 'sale' (TenantSale|null) and 'refund' (TenantSale|null) and 'transaction_id'.
     *   At least one of sale/refund will be non-null.
     */
    public function createTransaction(array $data): array
    {
        $hasNewSale = !empty($data['items']);
        $hasRefund  = !empty($data['refund']) && (!empty($data['refund']['item_ids']) || !empty($data['refund']['items'])); // MARKER-REFUND-QTY

        if (!$hasNewSale && !$hasRefund) {
            throw new SaleValidationException(
                'Transaction needs at least one new sale line or a refund.'
            );
        }

        $transactionId = (string) \Illuminate\Support\Str::uuid();

        return DB::transaction(function () use ($data, $hasNewSale, $hasRefund, $transactionId) {
            $refundRow = null;
            $saleRow   = null;

            if ($hasRefund) {
                $refundData = $data['refund'];
                $refundRow = $this->createRefund([
                    'tenant_id'          => $data['tenant_id'],
                    'original_sale_id'   => $refundData['original_sale_id'],
                    'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                    'refund_method'      => $refundData['refund_method'],
                    'reason'             => $refundData['reason'] ?? null,
                    'notes'              => $refundData['notes'] ?? null,
                    'item_ids'           => $refundData['item_ids'] ?? null,
                    'items'              => $refundData['items'] ?? null, // MARKER-REFUND-QTY
                ]);
                $refundRow->update(['transaction_id' => $transactionId]);
            }

            if ($hasNewSale) {
                $saleRow = $this->createSale([
                    'tenant_id'          => $data['tenant_id'],
                    'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                    'location_id'        => $data['location_id'],
                    'customer_id'        => $data['customer_id'] ?? null,
                    'status'             => 'completed',
                    'payment_status'     => $data['payment_status'] ?? 'paid',
                    'payment_method'     => $data['payment_method'] ?? null,
                    'payment_reference'  => $data['payment_reference'] ?? null,
                    'paid_at'            => $data['paid_at'] ?? Carbon::now(),
                    'notes'              => $data['notes'] ?? null,
                    'tip_cents'          => (int) ($data['tip_cents'] ?? 0),
                    'discount_cents'     => (int) ($data['discount_cents'] ?? 0),
                    'items'              => $data['items'],
                ]);
                $saleRow->update(['transaction_id' => $transactionId]);
            }

            return [
                'transaction_id' => $transactionId,
                'sale'           => $saleRow,
                'refund'         => $refundRow,
            ];
        });
    }

    /**
     * MARKER-SPLIT-TENDER — record register payments for a paid sale: one
     * ledger row per tender when a payments[] array was supplied (amounts
     * must sum to the sale total), else the single-tender row as before.
     */
    protected function recordRegisterPayments(\App\Models\Tenant\TenantSale $finalSale, array $data, string $kind): void
    {
        $svc = app(\App\Services\Tenant\SalePaymentService::class);
        $payments = $data['payments'] ?? null;

        if (is_array($payments) && count($payments) > 0) {
            $sum = array_sum(array_map(fn ($p) => (int) $p['amount_cents'], $payments));
            if ($sum !== (int) $finalSale->total_cents) {
                throw new \RuntimeException(sprintf(
                    'Split payments (%d¢) do not match the sale total (%d¢).',
                    $sum, (int) $finalSale->total_cents
                ));
            }
            foreach ($payments as $i => $p) {
                $svc->record(
                    sale:               $finalSale,
                    amountCents:        (int) $p['amount_cents'],
                    kind:               $i === 0 ? $kind : \App\Models\Tenant\TenantSalePayment::KIND_BALANCE,
                    source:             \App\Models\Tenant\TenantSalePayment::SOURCE_REGISTER,
                    method:             (string) $p['method'],
                    externalReference:  $p['reference'] ?? null,
                    notes:              "Split tender via sale {$finalSale->sale_number}",
                );
            }
            return;
        }

        $svc->record(
            sale:               $finalSale,
            amountCents:        (int) $finalSale->total_cents,
            kind:               $kind,
            source:             \App\Models\Tenant\TenantSalePayment::SOURCE_REGISTER,
            method:             $finalSale->payment_method ?? 'other',
            externalReference:  $finalSale->payment_reference,
            notes:              "Paid via sale {$finalSale->sale_number}",
        );
    }
}
REFQTY_2_EOF

cat > 'app/Http/Controllers/Tenant/RegisterController.php' <<'REFQTY_3_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\SaleService;
use App\Services\Tenant\SaleValidationException;
use App\Services\Tenant\InventoryStockException;
use App\Services\Tenant\DirectPaymentsService;  // MARKER-PATCH-170
use Illuminate\Support\Facades\Log;  // MARKER-PATCH-172B — missing import broke patches 170/170b/171/172
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RegisterController extends Controller
{
    public function __construct(protected SaleService $sales) {}

    public function index(Request $request)
    {
        $tenant = tenant();

        // Count of appointment-sourced drafts ready for checkout. Used to
        // render the "X ready for checkout" banner on the register page.
        $appointmentTrayCount = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->count();

        // Patch 46: pre-attach customer from query param (walk-in flow).
        $preAttachCustomer = null;
        $preCustId = $request->query('customer_id');
        if ($preCustId) {
            $cust = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $preCustId)
                ->first(['id', 'first_name', 'last_name', 'email', 'phone']);
            if ($cust) {
                $preAttachCustomer = [
                    'id'         => $cust->id,
                    'first_name' => $cust->first_name,
                    'last_name'  => $cust->last_name,
                    'name'       => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')),
                    'email'      => $cust->email,
                    'phone'      => $cust->phone,
                ];
            }
        }

        return view('tenant.register.index', [
            'tenant'     => $tenant,
            'offlineSyncEnabled' => app(\App\Services\FeatureAccessService::class)->hasAddon($tenant, 'offline_sync'), // MARKER-OFFLINE-SYNC
            'registers'  => \App\Models\Tenant\TenantRegister::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('number')->get(['id','number','name']), // MARKER-REGISTER-RECON-DISPLAY
            'currentRegisterId' => (int) $request->session()->get('current_register_id', 0), // MARKER-REGISTER-RECON-DISPLAY
            'manualTenders' => \App\Models\Tenant\TenantPaymentMethod::registerManualTenders($tenant), // MARKER-PATCH-630
            'preAttachCustomer' => $preAttachCustomer,
            'taxRate'    => (float) ($tenant->default_tax_rate ?? 0),
            'taxLabel'   => $this->taxLabel($tenant),
            'appointmentTrayCount' => $appointmentTrayCount,
            // MARKER-PATCH-162 — hide "Request transfer" button on oversell rows for single-location tenants
            'multiLocationActive' => (bool) $tenant->multi_location_active,
            'tipsConfig' => [
                'enabled'      => (bool) $tenant->tips_enabled,
                'method'       => $tenant->tip_default_method,
                'options'      => is_array($tenant->tip_default_options)
                                    ? $tenant->tip_default_options
                                    : (json_decode($tenant->tip_default_options ?? '[]', true) ?: []),
                'allow_custom' => (bool) $tenant->tip_allow_custom,
                'attributable' => (bool) $tenant->tip_attributable,
            ],
            'surchargeConfig' => [
                'enabled' => (bool) $tenant->passthrough_card_fees,
                'percent' => (float) ($tenant->card_surcharge_percent ?? 0),
                'label'   => $tenant->card_surcharge_label ?? 'Card processing fee',
            ],
        ]);
    }

    /**
     * List of appointment-sourced sales ready for checkout. Used by the
     * Register's "Ready for checkout" tray on the register home and by the
     * dedicated tray page.
     */
    public function appointmentTray(Request $request): JsonResponse
    {
        $tenant = tenant();
        $sales = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->with(['customer', 'appointment'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'sales' => $sales->map(function ($s) {
                $appt = $s->appointment;
                return [
                    'id'              => $s->id,
                    'sale_number'     => $s->sale_number,
                    'total_cents'     => (int) $s->total_cents,
                    'total_display'   => format_money((int) $s->total_cents),
                    'customer_name'   => $s->customer
                        ? trim(($s->customer->first_name ?? '') . ' ' . ($s->customer->last_name ?? ''))
                        : ($appt ? trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) : 'Walk-in'),
                    'appointment_id'  => $appt?->id,
                    'ra_number'       => $appt?->ra_number,
                    'created_at'      => $s->created_at?->toIso8601String(),
                    'item_count'      => (int) \DB::table('tenant_sale_items')->where('sale_id', $s->id)->count(),
                ];
            })->values(),
        ]);
    }

    /**
     * MARKER-PATCH-180 — dismiss a parked appointment draft sale from the
     * register tray. Voids the DRAFT sale (status=cancelled) so it leaves the
     * "ready for checkout" list. Non-destructive: only unpaid draft sales are
     * eligible; the appointment itself is untouched. The sale can be recreated
     * later from the appointment if needed.
     */
    public function dismissTraySale(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->first();

        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found or not dismissible.'], 404);
        }

        $sale->status = 'cancelled';
        $sale->save();

        return response()->json(['ok' => true]);
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));
        $type = $request->input('type', 'all');

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'services' => [], 'customers' => []]);
        }

        $products = [];
        $services = [];
        $customers = [];

        if ($type === 'all' || $type === 'product') {
            // patch-96 location stock — enrich each product with its on-hand
            // count at the CURRENT register location, so the cart can show an
            // oversell badge when qty exceeds that.
            $registerLocationId = $request->session()->get('current_location_id');
            $registerLocationName = null;
            if ($registerLocationId) {
                $loc = \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)
                    ->where('id', $registerLocationId)
                    ->first();
                $registerLocationName = $loc?->name;
            }

            $productItems = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                // MARKER-PATCH-552 — every word must hit SOMEWHERE across the
                // item's text: "Centerline Rotor 200mm" matches name+subtitle.
                ->where(function ($w) use ($q) {
                    foreach (array_filter(preg_split('/\s+/', $q)) as $t) {
                        $w->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc) LIKE ?", ['%' . $t . '%']);
                    }
                })
                ->limit(15)
                ->get();

            // One join to fetch all per-location counts for the matched items
            $stockByItem = [];
            if ($registerLocationId && $productItems->isNotEmpty()) {
                $stockByItem = \App\Models\Tenant\TenantInventoryItemLocation::whereIn(
                        'inventory_item_id', $productItems->pluck('id')
                    )
                    ->where('location_id', $registerLocationId)
                    ->pluck('computed_stock_count', 'inventory_item_id')
                    ->toArray();
            }

            $products = $productItems->map(fn ($p) => [
                'id'                     => $p->id,
                'name'                   => $p->name ?? '',
                'subtitle'               => $p->display_subtitle ?? '',
                'sku'                    => $p->sku ?? '',
                'price_cents'            => (int) ($p->effectiveSellPriceCents() ?? 0),
                'is_taxable'             => (($p->tax_class_code ?? null) !== 'exempt'),
                'allow_oversell'         => (bool) $p->allow_oversell,
                'current_location_stock' => (int) ($stockByItem[$p->id] ?? 0),
                'current_location_name'  => $registerLocationName,
            ])->toArray();
        }

        if ($type === 'all' || $type === 'service') {
            $services = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('is_active', 1)
                ->where('name', 'like', "%{$q}%")
                ->limit(15)
                ->get()
                ->map(fn ($s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'price_cents'      => (int) ($s->price_cents ?? 0),
                    'duration_minutes' => (int) ($s->duration_minutes ?? 0),
                ])
                ->toArray();
        }

        if ($type === 'all' || $type === 'customer') {
            $customers = TenantCustomer::where('tenant_id', $tenant->id)
                ->where(function ($w) use ($q) {
                    $w->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
                })
                ->limit(10)
                ->get()
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'email' => $c->email ?? '',
                    'phone' => $c->phone ?? '',
                ])
                ->toArray();
        }

        return response()->json(compact('products', 'services', 'customers'));
    }

    public function storeSale(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'discount_cents'   => 'nullable|integer|min:0',
            'payment_method'   => $this->allowedTenders(), // MARKER-PATCH-630
            'payment_reference'=> 'nullable|string',
            // MARKER-SPLIT-TENDER — optional multi-tender payments. When
            // present, payment_method is 'split' and applied amounts must sum
            // to the authoritative server-side total (checked in SaleService).
            'payments'                     => 'nullable|array|max:6',
            'payments.*.method'            => str_replace('required|', '', $this->allowedTenders()),
            'payments.*.amount_cents'      => 'required|integer|min:1',
            'payments.*.reference'         => 'nullable|string|max:120',
            'items'            => 'required|array|min:1',
            'items.*.type'             => 'required|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
            // MARKER-PATCH-161 — per-sale receipt skip
            'skip_receipt'             => 'nullable|boolean',
            // MARKER-OFFLINE-SYNC — idempotency key for offline replay
            'client_uuid'              => 'nullable|uuid',
        ]);

        // MARKER-OFFLINE-SYNC — replay dedupe: an offline queue may POST the
        // same sale more than once (retries, multiple tabs). Same client_uuid
        // returns the already-committed sale instead of double-selling.
        if (! empty($validated['client_uuid'])) {
            $existing = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->where('client_uuid', $validated['client_uuid'])
                ->first();
            if ($existing) {
                return response()->json([
                    'ok'          => true,
                    'sale_id'     => $existing->id,
                    'sale_number' => $existing->sale_number,
                    'total_cents' => $existing->total_cents,
                    'redirect'    => route('tenant.register.index'),
                    'replayed'    => true,
                ]);
            }
        }

        try {
            $sale = $this->sales->createSale([
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'register_id'        => $request->session()->get('current_register_id'), // MARKER-REGISTER-RECON-DISPLAY
                'client_uuid'        => $validated['client_uuid'] ?? null, // MARKER-OFFLINE-SYNC
                'customer_id'        => $validated['customer_id'] ?? null,
                'status'             => 'completed',
                'payment_status'     => 'paid',
                'payment_method'     => $validated['payment_method'],
                'payments'           => $validated['payments'] ?? null, // MARKER-SPLIT-TENDER
                'payment_reference'  => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'paid_at'            => Carbon::now(),
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'discount_cents'     => (int) ($validated['discount_cents'] ?? 0),
                'items'              => $validated['items'],
            ]);

            if ($validated['payment_method'] === 'card' && $tenant->passthrough_card_fees) {
                $surcharge = (int) round($sale->subtotal_cents * (($tenant->card_surcharge_percent ?? 0) / 100));
                if ($surcharge > 0) {
                    $sale->update(['surcharge_cents' => $surcharge]);
                    $sale = $this->sales->recalculate($sale->fresh('items'));
                }
            }

            // MARKER-PATCH-160 — auto-send receipt (queued, fail-open)
            // MARKER-PATCH-161 — skip if cashier opted out for this sale
            if (! $request->boolean('skip_receipt')) {
                \App\Jobs\SendSaleReceiptJob::dispatch($sale->id)->afterCommit();
            }

            return response()->json([
                'ok'          => true,
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
                'redirect'    => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Save (or update) a draft cart.
     * Called on every cart change with debounce. First call creates,
     * subsequent calls include 'id' and update.
     */
    public function storeDraft(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'id'               => 'nullable|uuid',
            'customer_id'      => 'nullable|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'metadata'         => 'nullable|array',
            'items'            => 'nullable|array',
            'items.*.type'             => 'required_with:items|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
        ]);

        try {
            $draft = $this->sales->saveDraft([
                'id'                 => $validated['id'] ?? null,
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'metadata'           => $validated['metadata'] ?? null,
                'items'              => $validated['items'] ?? [],
            ]);

            return response()->json([
                'ok'             => true,
                'draft_id'       => $draft->id,
                'subtotal_cents' => $draft->subtotal_cents,
                'tax_cents'      => $draft->tax_cents,
                'total_cents'    => $draft->total_cents,
                'updated_at'     => $draft->updated_at?->toIso8601String(),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * List open drafts at the current location.
     * Used by the resume banner on register load.
     */
    /**
     * patch-100a oversell actions — register cart "Request transfer" button.
     * Creates a pending TenantTransferRequest scoped to the current
     * register location. Returns the new request's id so the cart UI
     * can swap the button for a confirmation pill.
     */
    public function storeOversellTransferRequest(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        // MARKER-PATCH-162 — single-location tenants have nowhere to transfer FROM.
        // Defense in depth against stale tabs or URL fuzzing. Client UI already
        // hides the button, so a normal user can't hit this branch.
        if (! $tenant->multi_location_active) {
            return response()->json([
                'ok' => false,
                'error' => 'Transfer requests require at least two active locations.',
            ], 422);
        }

        // MARKER-TRANSFER-SCOPE — ownership enforced inside the query, not
        // merely existence: any tenant's id used to satisfy these rules.
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_items', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'quantity'          => 'nullable|integer|min:1',
            'sale_id'           => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_sales', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'notes'             => 'nullable|string|max:1000',
        ]);

        try {
            $svc = app(\App\Services\Tenant\TransferRequestService::class);
            $tr = $svc->create([
                'tenant_id'            => $tenant->id,
                'inventory_item_id'    => $validated['inventory_item_id'],
                'to_location_id'       => $locationId,
                'quantity'             => $validated['quantity'] ?? 1,
                'requested_by_user_id' => auth('tenant')->id(),
                'sale_id'              => $validated['sale_id'] ?? null,
                'notes'                => $validated['notes'] ?? null,
            ]);

            $fromLocName = $tr->fromLocation?->name;
            return response()->json([
                'ok'                  => true,
                'transfer_request_id' => $tr->id,
                'from_location_name'  => $fromLocName,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * patch-100a oversell actions — register cart "Add to order" button.
     * Creates a status=needed special order for the item, optionally
     * attached to a customer (if the cart has one). Returns the new
     * SO's id + number for confirmation display.
     */
    public function storeOversellSpecialOrder(Request $request): JsonResponse
    {
        $tenant = tenant();

        // MARKER-TRANSFER-SCOPE — same hardening as the transfer endpoint.
        // customer_id was previously unscoped and passed straight through to
        // SpecialOrderService, so a foreign customer could be attached.
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_items', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'quantity'          => 'nullable|integer|min:1',
            'customer_id'       => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_customers', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'notes'             => 'nullable|string|max:1000',
        ]);

        $item = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('id', $validated['inventory_item_id'])
            ->first();

        if (!$item) {
            return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
        }

        try {
            $svc = app(\App\Services\Tenant\SpecialOrderService::class);
            $so = $svc->create([
                'tenant_id'          => $tenant->id,
                'inventory_item_id'  => $item->id,
                'item_name_snapshot' => $item->name,
                'quantity'           => $validated['quantity'] ?? 1,
                'customer_id'        => $validated['customer_id'] ?? null,
                'status'             => \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED,
                'created_from'       => 'register',
                'notes'              => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'ok'                => true,
                'special_order_id'  => $so->id,
                'so_number'         => $so->so_number,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function listDrafts(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['drafts' => []]);
        }

        $drafts = TenantSale::where('tenant_id', $tenant->id)
            ->where('location_id', $locationId)
            ->drafts()
            ->with(['customer', 'rangUpBy', 'items'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(function ($d) {
                return [
                    'id'           => $d->id,
                    'item_count'   => $d->items->count(),
                    'total_cents'  => $d->total_cents,
                    'customer'     => $d->customer
                        ? trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? ''))
                        : null,
                    'started_by'   => $d->rangUpBy
                        ? trim(($d->rangUpBy->first_name ?? '') . ' ' . ($d->rangUpBy->last_name ?? ''))
                        : null,
                    'updated_at'   => $d->updated_at?->toIso8601String(),
                ];
            });

        return response()->json(['drafts' => $drafts]);
    }

    /**
     * Fetch a single draft with full line items, for resume into cart.
     */
    public function showDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $draft = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['draft', 'quote'])
            ->with(['customer', 'items'])
            ->first();

        if (!$draft) {
            return response()->json(['ok' => false, 'error' => 'Draft not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'draft' => [
                'id'          => $draft->id,
                'customer'    => $draft->customer ? [
                    'id'    => $draft->customer->id,
                    'name'  => trim(($draft->customer->first_name ?? '') . ' ' . ($draft->customer->last_name ?? '')),
                    'email' => $draft->customer->email ?? '',
                    'phone' => $draft->customer->phone ?? '',
                ] : null,
                'tip_cents'   => $draft->tip_cents,
                'notes'       => $draft->notes,
                'tax_locked'  => (bool) $draft->tax_locked,
                'tax_cents'   => (int) $draft->tax_cents,
                'items'       => $draft->items->map(fn ($i) => [
                    'type'              => $i->type,
                    'source_id'         => $i->service_id ?? $i->inventory_item_id,
                    'inventory_item_id' => $i->inventory_item_id,
                    'service_id'        => $i->service_id,
                    'name'              => $i->name_snapshot,
                    'price_cents'       => $i->unit_price_cents,
                    'qty'               => (float) $i->quantity,
                    'is_taxable'        => (bool) $i->is_taxable,
                    'tax_cents'         => (int) $i->tax_cents,
                    'tax_rate_snapshot' => $i->tax_rate_snapshot,
                ])->values(),
            ],
        ]);
    }

    /**
     * Permanently discard a draft.
     */
    public function discardDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        try {
            $this->sales->discardDraft($tenant->id, $id);
            return response()->json(['ok' => true]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * Promote a draft to a paid sale. Replaces storeSale for draft-backed flow.
     */
    public function commitDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'payment_method'    => $this->allowedTenders(), // MARKER-PATCH-630
            'payment_reference' => 'nullable|string',
            // MARKER-SPLIT-TENDER — optional multi-tender payments. When
            // present, payment_method is 'split' and applied amounts must sum
            // to the authoritative server-side total (checked in SaleService).
            'payments'                     => 'nullable|array|max:6',
            'payments.*.method'            => str_replace('required|', '', $this->allowedTenders()),
            'payments.*.amount_cents'      => 'required|integer|min:1',
            'payments.*.reference'         => 'nullable|string|max:120',
            'tip_cents'         => 'nullable|integer|min:0',
            'customer_id'       => 'nullable|uuid',
            'notes'             => 'nullable|string',
            // MARKER-PATCH-161 — per-sale receipt skip
            'skip_receipt'      => 'nullable|boolean',
        ]);

        try {
            $sale = $this->sales->commitDraft($tenant->id, $id, [
                'payment_status'    => 'paid',
                'payment_method'    => $validated['payment_method'],
                'payments'           => $validated['payments'] ?? null, // MARKER-SPLIT-TENDER
                'payment_reference' => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'paid_at'           => Carbon::now(),
                'tip_cents'         => $validated['tip_cents'] ?? null,
                'customer_id'       => $validated['customer_id'] ?? null,
                'notes'             => $validated['notes'] ?? null,
            ]);

            // Apply card surcharge same as storeSale path.
            if ($validated['payment_method'] === 'card' && $tenant->passthrough_card_fees) {
                $surcharge = (int) round($sale->subtotal_cents * (($tenant->card_surcharge_percent ?? 0) / 100));
                if ($surcharge > 0) {
                    $sale->update(['surcharge_cents' => $surcharge]);
                    $sale = $this->sales->recalculate($sale->fresh('items'));
                }
            }

            // MARKER-PATCH-160 — auto-send receipt (queued, fail-open)
            // MARKER-PATCH-161 — skip if cashier opted out for this sale
            if (! $request->boolean('skip_receipt')) {
                \App\Jobs\SendSaleReceiptJob::dispatch($sale->id)->afterCommit();
            }

            return response()->json([
                'ok'          => true,
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
                'redirect'    => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Look up a past sale by sale_number for the refund picker.
     * Returns the sale's line items with refundable quantities.
     */
    public function lookupSaleForRefund(Request $request): JsonResponse
    {
        $tenant = tenant();
        $saleNumber = trim((string) $request->input('sale_number', ''));

        if ($saleNumber === '') {
            return response()->json(['ok' => false, 'error' => 'Sale number required.'], 422);
        }

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('sale_number', $saleNumber)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNull('refund_of_sale_id')
            ->with(['customer', 'items', 'refunds.items'])
            ->first();

        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found or not refundable.'], 404);
        }

        // For each original line, compute quantity already refunded across all
        // prior refund rows. Refundable_qty = original_qty - already_refunded_qty.
        $refundedByOrigItem = [];
        foreach ($sale->refunds as $refund) {
            foreach ($refund->items as $rline) {
                // Refund lines snapshot the same product/service/etc.
                // We match by (type, source_id, name_snapshot) since refund lines
                // don't carry a back-reference to the original line.
                $key = $rline->type . '|'
                    . ($rline->inventory_item_id ?? $rline->service_id ?? '')
                    . '|' . $rline->name_snapshot;
                $refundedByOrigItem[$key] = ($refundedByOrigItem[$key] ?? 0) + (float) $rline->quantity;
            }
        }

        $items = $sale->items->map(function ($i) use ($refundedByOrigItem) {
            $key = $i->type . '|'
                . ($i->inventory_item_id ?? $i->service_id ?? '')
                . '|' . $i->name_snapshot;
            $already = $refundedByOrigItem[$key] ?? 0;
            $remaining = max(0, (float) $i->quantity - $already);
            return [
                'id'                => $i->id,
                'type'              => $i->type,
                'name'              => $i->name_snapshot,
                'quantity'          => (float) $i->quantity,
                'already_refunded'  => $already,
                'remaining'         => $remaining,
                'unit_price_cents'  => $i->unit_price_cents,
                'line_total_cents'  => $i->line_total_cents,
                'tax_cents'         => (int) $i->tax_cents,
                'is_taxable'        => (bool) $i->is_taxable,
            ];
        })->values();

        return response()->json([
            'ok'   => true,
            'sale' => [
                'id'             => $sale->id,
                'sale_number'    => $sale->sale_number,
                'sale_date'      => $sale->sale_date?->toDateString(),
                'paid_at'        => $sale->paid_at?->toDateTimeString(),
                'total_cents'    => $sale->total_cents,
                'tender'         => $sale->payment_method,
                'customer'       => $sale->customer
                    ? trim(($sale->customer->first_name ?? '') . ' ' . ($sale->customer->last_name ?? ''))
                    : null,
                'items'          => $items,
            ],
        ]);
    }

    /**
     * MARKER-PATCH-177 — Standalone refund: money out with NO sale attached.
     *
     * For refunds that aren't tied to a past sale in the system — e.g. refunding
     * a fee charged before Intake existed. Always carries a customer; sale_id is
     * null. Writes one negative row directly through the sale-payment ledger so
     * it shows in "money out" / Payments Received reporting alongside everything
     * else. Uncapped (there is no sale total to cap against — the operator types
     * the amount). Line-item refunds against an existing sale keep using the
     * existing storeTransaction flow; this is the no-sale path only.
     */
    public function storeStandaloneRefund(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');
        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'   => 'required|uuid',
            'amount_cents'  => 'required|integer|min:1',
            'refund_method' => 'required|string|in:cash,card,check,store_credit,mark_paid',
            'reason'        => 'required|string|max:500',
        ]);

        // Customer must belong to this tenant (defense against cross-tenant ids).
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $validated['customer_id'])
            ->first();
        if (!$customer) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        try {
            $payment = app(\App\Services\Tenant\SalePaymentService::class)->recordStandaloneRefund(
                tenantId:    $tenant->id,
                customerId:  $customer->id,
                amountCents: (int) $validated['amount_cents'],
                method:      $validated['refund_method'],
                reason:      $validated['reason'],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Standalone refund failed', [
                'tenant_id'   => $tenant->id,
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Could not record refund.'], 500);
        }

        return response()->json([
            'ok'            => true,
            'payment_id'    => $payment->id,
            'amount_cents'  => abs($payment->amount_cents),
            'customer'      => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
            'method'        => $validated['refund_method'],
        ]);
    }

    /**
     * Commit a multi-row transaction (mixed sale + refund, or pure refund).
     * Pure sales still use storeSale or commitDraft.
     */
    public function storeTransaction(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|uuid',
            'tip_cents'        => 'nullable|integer|min:0',
            'payment_method'   => $this->allowedTenders(['even_exchange']), // MARKER-PATCH-630
            'payment_reference'=> 'nullable|string',
            'items'            => 'nullable|array',
            'items.*.type'             => 'required_with:items|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.is_taxable'       => 'nullable|boolean',
            // MARKER-SPLIT-TENDER — optional multi-tender payments. When
            // present, payment_method is 'split' and applied amounts must sum
            // to the authoritative server-side total (checked in SaleService).
            'payments'                     => 'nullable|array|max:6',
            'payments.*.method'            => str_replace('required|', '', $this->allowedTenders()),
            'payments.*.amount_cents'      => 'required|integer|min:1',
            'payments.*.reference'         => 'nullable|string|max:120',
            'refund'                       => 'required|array',
            'refund.original_sale_id'      => 'required|uuid',
            // MARKER-REFUND-QTY — quantity- and disposition-aware refund lines.
            // item_ids stays accepted (read as "full remaining, restocked") so
            // an older tab mid-transaction still works.
            'refund.item_ids'              => 'required_without:refund.items|array|min:1',
            'refund.item_ids.*'            => 'uuid',
            'refund.items'                 => 'required_without:refund.item_ids|array|min:1',
            'refund.items.*.sale_item_id'  => 'required|uuid',
            'refund.items.*.quantity'      => 'required|numeric|min:0.001',
            'refund.items.*.disposition'   => 'nullable|string|in:' . implode(',', \App\Services\Tenant\SaleService::DISPOSITIONS),
            'refund.refund_method'         => 'required|string|in:cash,card,check,store_credit,mark_paid,even_exchange',
        ]);

        try {
            $result = $this->sales->createTransaction([
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'payment_method'     => $validated['payment_method'],
                'payments'           => $validated['payments'] ?? null, // MARKER-SPLIT-TENDER
                'payment_reference'  => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'items'              => $validated['items'] ?? [],
                'refund'             => $validated['refund'],
                'payments'           => $validated['payments'] ?? null, // MARKER-SPLIT-TENDER
            ]);

            // Build a unified receipt response.
            $sale = $result['sale'];
            $refund = $result['refund'];

            // MARKER-PATCH-171 — fire Stripe refund if refund half exists and
            // refund_method=card. Mirrors storeRefund behavior for the mixed path.
            $stripeRefundError = null;
            if ($refund && ($validated['refund']['refund_method'] ?? null) === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }

            return response()->json([
                'ok'             => true,
                'transaction_id' => $result['transaction_id'],
                'sale_id'        => $sale?->id,
                'sale_number'    => $sale?->sale_number ?? $refund?->sale_number,
                'total_cents'    => ($sale?->total_cents ?? 0) - ($refund?->total_cents ?? 0),
                'sale_total'     => $sale?->total_cents ?? 0,
                'refund_total'   => $refund?->total_cents ?? 0,
                // MARKER-PATCH-171 — Stripe refund outcome
                'stripe_refund_error' => $stripeRefundError ?? null,
                'stripe_refund_id'    => $refund?->fresh()?->stripe_refund_id,
                'redirect'       => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Transaction History — list view of every tenant_sales row,
     * including drafts/quotes/paid/partial/refunded.
     * Filtered and sorted client-side by the page JS.
     */
    public function historyIndex(Request $request)
    {
        $tenant = tenant();

        $rows = TenantSale::where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'location'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function ($r) {
                return [
                    'id'             => $r->id,
                    'sale_number'    => $r->sale_number,
                    'payment_status' => $r->payment_status,
                    'item_count'     => $r->items->count(),
                    'total_cents'    => $r->total_cents,
                    'transaction_id' => $r->transaction_id,
                    'customer'       => $r->customer
                        ? trim(($r->customer->first_name ?? '') . ' ' . ($r->customer->last_name ?? ''))
                        : null,
                    'customer_email' => $r->customer->email ?? null,
                    'started_by'     => $r->rangUpBy
                        ? trim(($r->rangUpBy->first_name ?? '') . ' ' . ($r->rangUpBy->last_name ?? ''))
                        : null,
                    'location_name'  => $r->location->name ?? null,
                    'is_refund'      => $r->refund_of_sale_id !== null,
                    'refund_of_sale_number' => $r->refund_of_sale_id
                        ? \App\Models\Tenant\TenantSale::where('id', $r->refund_of_sale_id)->value('sale_number')
                        : null,
                    'updated_at'     => $r->updated_at?->toIso8601String(),
                    'paid_at'        => $r->paid_at?->toIso8601String(),
                    'sale_date'      => $r->sale_date?->toDateString(),
                ];
            });

        return view('tenant.register.history', [
            'tenant' => $tenant,
            'rows'   => $rows,
        ]);
    }

    /**
     * Return a single sale as JSON for the sale-detail modal.
     * Read-only. Used by the history page and customer activity timeline.
     */
    /**
     * MARKER-PATCH-231A — sale detail PAGE (the JSON sibling feeds the
     * register modal; this is a linkable page for search + history).
     */
    public function showSalePage(Request $request, string $id)
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'payments', 'location', 'refundOf:id,sale_number', 'appointment:id,ra_number'])
            ->firstOrFail();

        $refunds = TenantSale::where('refund_of_sale_id', $sale->id)
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get(['id', 'sale_number', 'total_cents', 'created_at']);

        // MARKER-PATCH-231 — linked context (rental/lease the sale belongs to).
        $linkedRental = $sale->rental_id
            ? \App\Models\Tenant\TenantRental::where('tenant_id', $tenant->id)->find($sale->rental_id, ['id', 'rental_number'])
            : null;
        $linkedLease = $sale->lease_id
            ? \App\Models\Tenant\Lease::where('tenant_id', $tenant->id)->find($sale->lease_id, ['id', 'lease_number'])
            : null;

        return view('tenant.register.sale-show', [
            'sale'         => $sale,
            'refunds'      => $refunds,
            'linkedRental' => $linkedRental,
            'linkedLease'  => $linkedLease,
        ]);
    }

    // MARKER-PATCH-319 — render the printable 80mm sales receipt.
    public function printReceipt(Request $request, string $id)
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'items', 'payments'])
            ->firstOrFail();

        $cfg   = (array) (($tenant->settings['work_order_tag'] ?? []));
        $print = \App\Services\PrintIdentityService::forTenant($tenant); // MARKER-PATCH-332
        $embed = $request->boolean('embed');

        return view('tenant.register.receipt', compact('tenant', 'sale', 'print', 'embed'));
    }

    public function showSaleJson(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'location', 'refundOf:id,sale_number'])
            ->first();

        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Load related refunds (children) so the modal can summarize them.
        $refunds = TenantSale::where('refund_of_sale_id', $sale->id)
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get(['id', 'sale_number', 'total_cents', 'paid_at', 'created_at'])
            ->map(fn ($r) => [
                'id'          => $r->id,
                'sale_number' => $r->sale_number,
                'total_cents' => (int) $r->total_cents,
                'paid_at'     => $r->paid_at?->toIso8601String() ?? $r->created_at?->toIso8601String(),
            ])
            ->values();

        $items = $sale->items
            ->sortBy(fn ($i) => $i->position ?? 0)
            ->values()
            ->map(fn ($i) => [
                'type'             => $i->type,
                'name'             => $i->name_snapshot,
                'description'      => $i->description_snapshot,
                'quantity'         => (float) $i->quantity,
                'unit_price_cents' => (int) $i->unit_price_cents,
                'discount_cents'   => (int) $i->discount_cents,
                'tax_cents'        => (int) $i->tax_cents,
                'is_taxable'       => (bool) $i->is_taxable,
                'line_total_cents' => (int) $i->line_total_cents,
            ]);

        // MARKER-PATCH-191 — the payment ledger for this sale (each deposit /
        // balance / payment / refund row), so the modal shows exactly what was
        // paid, how, and when — not just the sale total.
        $payments = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id, // MARKER-PATCH-198 — targets delete
                'amount_cents' => (int) $p->amount_cents,
                'kind'         => $p->kind,
                'method'       => $p->method,
                'method_label' => method_exists($p, 'methodLabel') ? $p->methodLabel() : $p->method,
                'source'       => $p->source,
                'reference'    => $p->external_reference,
                'notes'        => $p->notes,
                'recorded_at'  => $p->recorded_at?->toIso8601String(),
                'is_refund'    => $p->amount_cents < 0,
            ])
            ->values();
        $paidCents = (int) $payments->sum('amount_cents');

        // MARKER-PATCH-161 — email send log for this sale.
        $sendLog = \App\Models\Tenant\TenantNotificationLog::where('tenant_id', $tenant->id)
            ->where('related_type', 'sale')
            ->where('related_id', $sale->id)
            ->where('channel', 'email')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['event_type','recipient','status','error_message','template_key','created_at'])
            ->map(fn ($r) => [
                'event_type'   => $r->event_type,
                'recipient'    => $r->recipient,
                'status'       => $r->status,
                'error'        => $r->error_message,
                'template_key' => $r->template_key,
                'created_at'   => $r->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'ok'   => true,
            'sale' => [
                'id'             => $sale->id,
                'sale_number'    => $sale->sale_number,
                'status'         => $sale->status,
                'payment_status' => $sale->payment_status,
                'is_refund'      => $sale->refund_of_sale_id !== null,
                'is_quote'       => $sale->payment_status === 'quote',
                'is_draft'       => $sale->payment_status === 'draft',
                'sale_date'      => $sale->sale_date?->toDateString(),
                'paid_at'        => $sale->paid_at?->toIso8601String(),
                'created_at'     => $sale->created_at?->toIso8601String(),
                'updated_at'     => $sale->updated_at?->toIso8601String(),
                'transaction_id' => $sale->transaction_id,
                'payment_method' => $sale->payment_method,
                'payment_reference' => $sale->payment_reference,
                'notes'          => $sale->notes,
                'subtotal_cents' => (int) $sale->subtotal_cents,
                'discount_cents' => (int) $sale->discount_cents,
                'tax_cents'      => (int) $sale->tax_cents,
                'surcharge_cents'=> (int) $sale->surcharge_cents,
                'tip_cents'      => (int) $sale->tip_cents,
                'total_cents'    => (int) $sale->total_cents,
                'customer'       => $sale->customer ? [
                    'id'    => $sale->customer->id,
                    'name'  => trim(($sale->customer->first_name ?? '') . ' ' . ($sale->customer->last_name ?? '')),
                    'email' => $sale->customer->email,
                    'phone' => $sale->customer->phone,
                ] : null,
                'rang_up_by'     => $sale->rangUpBy
                    ? trim(($sale->rangUpBy->first_name ?? '') . ' ' . ($sale->rangUpBy->last_name ?? ''))
                    : null,
                'location_name'  => $sale->location->name ?? null,
                'refund_of'      => $sale->refundOf ? [
                    'id'          => $sale->refundOf->id,
                    'sale_number' => $sale->refundOf->sale_number,
                ] : null,
                'refunds'        => $refunds,
                'items'          => $items,
                'send_log'       => $sendLog,
                'payments'       => $payments,
                'paid_cents'     => $paidCents,
                // MARKER-PATCH-195 — checkout link fields for the status view.
                'checkout_session_id' => $sale->checkout_session_id,
                'sale_status'    => $sale->status,
                'appointment_id' => $sale->appointment_id,
            ],
        ]);
    }

    /**
     * Quotes list with dashboard metrics on top.
     * Cards: open quotes, aging (>14 days), new this week, recently converted.
     */
    public function quotesIndex(Request $request)
    {
        $tenant = tenant();
        $now = Carbon::now();
        $agingThreshold = $now->copy()->subDays(14);
        $oneWeekAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Open quotes: total count + dollar value.
        $openQuotesQuery = TenantSale::where('tenant_id', $tenant->id)->quotes();
        $openCount = (clone $openQuotesQuery)->count();
        $openValueCents = (clone $openQuotesQuery)->sum('total_cents');

        // Aging: quotes where updated_at < 14 days ago.
        $agingQuery = (clone $openQuotesQuery)->where('updated_at', '<', $agingThreshold);
        $agingCount = (clone $agingQuery)->count();
        $agingValueCents = (clone $agingQuery)->sum('total_cents');
        $oldestAging = (clone $agingQuery)->orderBy('updated_at')->value('updated_at');
        $oldestAgingDays = $oldestAging
            ? (int) Carbon::parse($oldestAging)->diffInDays($now)
            : 0;

        // New this week: quotes created (created_at) in last 7 days.
        $newThisWeekQuery = (clone $openQuotesQuery)->where('created_at', '>=', $oneWeekAgo);
        $newThisWeekCount = (clone $newThisWeekQuery)->count();
        $newThisWeekValueCents = (clone $newThisWeekQuery)->sum('total_cents');

        // Recently converted: paid sales with was_quote=true in last 30 days.
        $convertedQuery = TenantSale::where('tenant_id', $tenant->id)
            ->where('was_quote', true)
            ->where('payment_status', 'paid')
            ->where('paid_at', '>=', $thirtyDaysAgo);
        $convertedCount = (clone $convertedQuery)->count();
        $convertedValueCents = (clone $convertedQuery)->sum('total_cents');

        // Conversion rate: of all quotes created in last 30 days, what fraction are now converted?
        $quotesCreated30d = TenantSale::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where(function ($q) {
                // Either currently a quote, or was one and is now paid.
                $q->where('payment_status', 'quote')
                  ->orWhere(function ($qq) {
                      $qq->where('was_quote', true)->where('payment_status', 'paid');
                  });
            })
            ->count();
        $conversionRate = $quotesCreated30d > 0
            ? round(($convertedCount / $quotesCreated30d) * 100)
            : null;

        $dashboard = [
            'open' => [
                'count' => $openCount,
                'value_cents' => (int) $openValueCents,
            ],
            'aging' => [
                'count' => $agingCount,
                'value_cents' => (int) $agingValueCents,
                'oldest_days' => $oldestAgingDays,
            ],
            'new_this_week' => [
                'count' => $newThisWeekCount,
                'value_cents' => (int) $newThisWeekValueCents,
            ],
            'converted' => [
                'count' => $convertedCount,
                'value_cents' => (int) $convertedValueCents,
                'rate_pct' => $conversionRate,
            ],
            'aging_threshold_days' => 14,
        ];

        $quotes = TenantSale::where('tenant_id', $tenant->id)
            ->quotes()
            ->with(['customer', 'rangUpBy', 'items', 'location'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function ($q) {
                return [
                    'id'           => $q->id,
                    'item_count'   => $q->items->count(),
                    'total_cents'  => $q->total_cents,
                    'customer'     => $q->customer
                        ? trim(($q->customer->first_name ?? '') . ' ' . ($q->customer->last_name ?? ''))
                        : null,
                    'customer_email' => $q->customer->email ?? null,
                    'started_by'   => $q->rangUpBy
                        ? trim(($q->rangUpBy->first_name ?? '') . ' ' . ($q->rangUpBy->last_name ?? ''))
                        : null,
                    'location_name' => $q->location->name ?? null,
                    'notes'        => $q->notes,
                    'updated_at'   => $q->updated_at?->toIso8601String(),
                    'created_at'   => $q->created_at?->toIso8601String(),
                ];
            });

        return view('tenant.register.quotes', [
            'tenant'    => $tenant,
            'quotes'    => $quotes,
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * Save the current cart as a quote.
     * Customer is required (the modal enforces it client-side too).
     */
    public function storeQuote(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'id'               => 'nullable|uuid',
            'customer_id'      => 'required|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'items'            => 'required|array|min:1',
            'items.*.type'             => 'required|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
        ]);

        try {
            $quote = $this->sales->saveQuote([
                'id'                 => $validated['id'] ?? null,
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'],
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'items'              => $validated['items'],
            ]);

            return response()->json([
                'ok'          => true,
                'quote_id'    => $quote->id,
                'total_cents' => $quote->total_cents,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /register/item/{id}/info — MARKER-PATCH-552
     * Everything staff want to see about an item mid-sale: identity,
     * price, per-location stock, and the catalog image when linked.
     */
    public function itemInfo(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        $item = TenantInventoryItem::with(['category', 'distributorCatalog'])
            ->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $stock = \App\Models\Tenant\TenantInventoryItemLocation::query()
            ->where('inventory_item_id', $item->id)
            ->get()
            ->map(function ($row) use ($tenant) {
                $loc = \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)->where('id', $row->location_id)->first();
                return ['location' => $loc?->name ?? '—', 'count' => (int) $row->computed_stock_count];
            })->values();

        // MARKER-PATCH-553 — HLC stores images as objects; pull usable URLs.
        $images = collect((array) ($item->distributorCatalog?->images ?? []))
            ->map(function ($im) {
                if (is_string($im)) return $im;
                if (is_array($im)) return $im['Url'] ?? $im['url'] ?? $im['src'] ?? null;
                return null;
            })->filter()->values()->all();

        // Specs from canonical attributes
        $attrs = collect((array) ($item->distributorCatalog?->attributes ?? []))
            ->filter(fn ($a) => is_array($a) && !empty($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '')
            ->map(fn ($a) => ['name' => $a['Name'], 'value' => $a['Value']])
            ->values()->all();

        // Sold in the last 30 days (real sales only)
        $sold30 = (float) \App\Models\Tenant\TenantSaleItem::query()
            ->where('inventory_item_id', $item->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereHas('sale', fn ($q) => $q->where('tenant_id', $tenant->id)
                ->whereNotIn('payment_status', ['draft', 'quote']))
            ->sum('quantity');

        // MARKER-PATCH-553 — cost/margin only for roles with the capability
        $user = \Illuminate\Support\Facades\Auth::guard('tenant')->user(); // MARKER-PATCH-554
        $costPayload = null;
        if ($user && $user->canAccessSection('cost_margins')) {
            $cost  = (int) ($item->effectiveCostCents() ?? 0);
            $price = (int) ($item->effectiveSellPriceCents() ?? 0);
            $costPayload = [
                'cost_cents' => $cost,
                'margin_pct' => ($price > 0 && $cost > 0) ? round((($price - $cost) / $price) * 100, 1) : null,
            ];
        }

        return response()->json([
            'ok'          => true,
            'name'        => $item->name,
            'brand'       => $item->distributorCatalog?->manufacturer,
            'subtitle'    => $item->display_subtitle,
            'description' => $item->description,
            'sku'         => $item->sku,
            'upc'         => $item->catalog_upc,
            'category'    => $item->category?->name,
            'price_cents' => (int) ($item->effectiveSellPriceCents() ?? 0),
            'taxable'     => (($item->tax_class_code ?? null) !== 'exempt'),
            'images'      => array_slice($images, 0, 4),
            'attrs'       => $attrs,
            'sold_30d'    => $sold30,
            'cost'        => $costPayload,
            'edit_url'    => route('tenant.inventory.edit', $item->id),
            'stock'       => $stock,
        ]);
    }

    public function searchRefundables(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['sales' => []]);
        }

        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNull('refund_of_sale_id')
            ->where(function ($w) use ($q) {
                $w->where('sale_number', 'like', "%{$q}%")
                  ->orWhereHas('customer', function ($c) use ($q) {
                      $c->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                  });
            })
            ->orderByDesc('paid_at')
            ->limit(20)
            ->with(['customer', 'items'])
            ->get()
            ->map(function ($s) {
                return [
                    'id'          => $s->id,
                    'sale_number' => $s->sale_number,
                    'sale_date'   => $s->sale_date?->toDateString(),
                    'paid_at'     => $s->paid_at?->toDateTimeString(),
                    'total_cents' => $s->total_cents,
                    'tender'      => $s->payment_method,
                    'customer'    => $s->customer
                        ? trim(($s->customer->first_name ?? '') . ' ' . ($s->customer->last_name ?? ''))
                        : null,
                    'items'       => $s->items->map(fn ($i) => [
                        'id'               => $i->id,
                        'name'             => $i->name_snapshot,
                        'quantity'         => $i->quantity,
                        'line_total_cents' => $i->line_total_cents,
                        'type'             => $i->type,
                    ])->toArray(),
                ];
            });

        return response()->json(['sales' => $sales]);
    }

    // MARKER-PATCH-160 — re-send (or send to another email) a sale receipt
    public function resendReceipt(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();
        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Optional override — "send to another email" on the sale-detail card.
        $override = trim((string) $request->input('email', ''));
        if ($override !== '' && !filter_var($override, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Invalid email address.'], 422);
        }

        \App\Jobs\SendSaleReceiptJob::dispatch($sale->id, $override ?: null, 'manual_resend');
        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-PATCH-170 — Direct Payments Session 2A.
     *
     * Create a Stripe PaymentIntent for a cart. Returns the client_secret
     * which the front-end uses with Stripe.js to confirm the card.
     *
     * This is called BEFORE the sale is committed. After Stripe confirms
     * the payment client-side, the front-end POSTs to /register/sales (or
     * /register/drafts/{id}/commit) with payment_method=card AND the
     * stripe_payment_intent_id so the controller can verify + record.
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();

        // MARKER-PATCH-618 — tenant toggle gates NEW payments (refunds untouched).
        if (! $tenant->direct_payments_enabled || ! ($tenant->settings['stripe_register_enabled'] ?? true)) {
            return response()->json(['error' => 'Card payments are turned off in Settings → Payments.'], 422);
        }

        $validated = $request->validate([
            'amount_cents'      => 'required|integer|min:50',
            'sale_id'           => 'nullable|uuid',
            // MARKER-PATCH-170B — preflight payload so we can validate before charging
            'customer_id'       => 'nullable|uuid',
            'has_service_line'  => 'nullable|boolean',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'Card payments are not enabled for this tenant.',
            ], 422);
        }

        // MARKER-PATCH-170B — pre-charge cart validation. Mirrors SaleService
        // checks so we never authorize a card for a sale that won\'t commit.
        if (! empty($validated['has_service_line']) && empty($validated['customer_id'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Customer is required when the sale has any service line.',
            ], 422);
        }

        try {
            $pi = $direct->createPaymentIntent($validated['amount_cents'], 'usd', array_filter([
                'intake_sale_id' => $validated['sale_id'] ?? null,
            ]));
            return response()->json([
                'ok'             => true,
                'client_secret'  => $pi->client_secret,
                'payment_intent' => $pi->id,
                'publishable_key' => $direct->publishableKey(),
                'mode'           => $direct->mode(),
            ]);
        } catch (\Throwable $e) {
            Log::error('direct_payments.create_pi_failed', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Could not initialize card payment. Verify your Stripe keys are correct.',
            ], 500);
        }
    }

    /**
     * Verify a PaymentIntent's final state with Stripe. Returns the card
     * details if succeeded so the front-end can include them in the
     * subsequent commit call (which writes them to the sale row).
     */
    public function confirmPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'payment_intent' => 'required|string',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        try {
            $pi = $direct->retrievePaymentIntent($validated['payment_intent']);
        } catch (\Throwable $e) {
            Log::error('direct_payments.retrieve_pi_failed', [
                'tenant_id' => $tenant->id,
                'pi'        => $validated['payment_intent'],
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Could not verify payment.'], 500);
        }

        if ($pi->status !== 'succeeded') {
            return response()->json([
                'ok'     => false,
                'status' => $pi->status,
                'error'  => 'Payment is not in a succeeded state (status: ' . $pi->status . ').',
            ], 409);
        }

        $card = $direct->extractCardDetails($pi);
        return response()->json([
            'ok'                       => true,
            'payment_intent'           => $pi->id,
            'stripe_charge_id'         => $card['charge_id'],
            'card_brand'               => $card['brand'],
            'card_last4'               => $card['last4'],
            'card_funding'             => $card['funding'],
            'amount_received_cents'    => $pi->amount_received,
        ]);
    }

    /**
     * MARKER-PATCH-172 — Create a Stripe Checkout Session and a matching
     * DRAFT sale that\'s waiting for the customer to pay remotely.
     *
     * Returns the Checkout URL (for QR + copy/share) and the draft sale ID
     * (which the frontend polls until the webhook fires).
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();

        // MARKER-PATCH-618 — tenant toggle gates NEW payment links (refunds untouched).
        if (! $tenant->direct_payments_enabled || ! ($tenant->settings['stripe_register_enabled'] ?? true)) {
            return response()->json(['error' => 'Payment links are turned off in Settings → Payments.'], 422);
        }

        $validated = $request->validate([
            'amount_cents'     => 'required|integer|min:50',
            'customer_id'      => 'nullable|uuid',
            'has_service_line' => 'nullable|boolean',
            'description'      => 'nullable|string|max:255',
            // MARKER-PATCH-178B — when present, bind the link to THIS existing
            // sale instead of minting a new (appointment-less) one. This is the
            // resumed parked sale's id (cart.draft_id on the frontend).
            'sale_id'          => 'nullable|uuid',
            'items'            => 'required|array|min:1',
            'tip_cents'        => 'nullable|integer|min:0',
            'discount_cents'   => 'nullable|integer|min:0',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        // Same pre-check as createPaymentIntent (defense in depth).
        if (! empty($validated['has_service_line']) && empty($validated['customer_id'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Customer is required when the sale has any service line.',
            ], 422);
        }

        // Resolve customer email for receipt (Stripe Checkout will pre-fill it).
        $customerEmail = null;
        if (! empty($validated['customer_id'])) {
            $customerEmail = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $validated['customer_id'])
                ->value('email');
        }

        // Create the Checkout Session first — if Stripe fails, no draft sale orphan.
        $description = $validated['description'] ?: ($tenant->name . ' — purchase');
        try {
            $session = $direct->createCheckoutSession(
                $validated['amount_cents'],
                $description,
                array_filter([
                    'customer_email' => $customerEmail,
                ])
            );
        } catch (\Throwable $e) {
            Log::error('direct_payments.checkout_session_failed', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Could not create payment link. Verify your Stripe keys.',
            ], 500);
        }

        // MARKER-PATCH-178B — bind the session to an EXISTING sale when sale_id
        // is given (resumed parked/appointment sale), instead of minting a new
        // appointment-less sale. This was the link-detach bug: link-paid
        // appointments stayed unpaid because the charge landed on a separate
        // sale. If no sale_id, fall back to creating a draft as before.
        $locationId = $request->session()->get('current_location_id');
        try {
            if (!empty($validated['sale_id'])) {
                $draftSale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                    ->where('id', $validated['sale_id'])
                    ->whereNotIn('status', ['cancelled', 'closed'])
                    ->first();
                if (!$draftSale) {
                    return response()->json(['ok' => false, 'error' => 'Sale not found to attach the link.'], 404);
                }
                $draftSale->checkout_session_id = $session->id;
                if (in_array($draftSale->payment_status, ['draft'], true)) {
                    $draftSale->payment_status = 'unpaid';
                }
                $draftSale->payment_method    = $draftSale->payment_method ?: 'card';
                $draftSale->payment_reference = $draftSale->payment_reference ?: 'Awaiting payment link';
                $draftSale->save();
            } else {
                $draftSale = $this->sales->createSale([
                    'tenant_id'          => $tenant->id,
                    'rang_up_by_user_id' => auth('tenant')->id(),
                    'location_id'        => $locationId,
                    'customer_id'        => $validated['customer_id'] ?? null,
                    'status'             => 'pending',
                    'payment_status'     => 'unpaid',
                    'payment_method'     => 'card',
                    'payment_reference'  => 'Awaiting payment link',
                    'paid_at'            => null,
                    'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                    'discount_cents'     => (int) ($validated['discount_cents'] ?? 0),
                    'items'              => $validated['items'],
                    'checkout_session_id' => $session->id,
                ]);
            }
        } catch (\Throwable $e) {
            // If draft creation fails, expire the Stripe session immediately so
            // the link can\'t be paid (we have nothing to attach it to).
            try {
                $direct->client = null; // ignore typing — best-effort
            } catch (\Throwable $_) {}
            Log::error('direct_payments.draft_sale_failed', [
                'tenant_id'  => $tenant->id,
                'session_id' => $session->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Could not stage the sale. ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'           => true,
            'sale_id'      => $draftSale->id,
            'session_id'   => $session->id,
            'checkout_url' => $session->url,
            'expires_at'   => $session->expires_at,
        ]);
    }

    /**
     * MARKER-PATCH-172 — Poll status of a Checkout Session. Frontend calls
     * this every ~3 seconds while the payment-link modal is open.
     *
     * Returns one of: pending (still waiting), succeeded (payment_status
     * went paid via webhook), expired, or cancelled.
     */
    public function checkCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();

        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Primary check: the webhook handler updates payment_status=paid when
        // checkout.session.completed fires. Read DB first to avoid hitting Stripe.
        if ($sale->payment_status === 'paid') {
            return response()->json([
                'ok'          => true,
                'status'      => 'succeeded',
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
            ]);
        }

        // Fallback: hit Stripe directly in case webhook is delayed/dropped.
        $direct = new DirectPaymentsService($tenant);
        if ($sale->checkout_session_id) {
            try {
                $session = $direct->retrieveCheckoutSession($sale->checkout_session_id);
                if ($session->payment_status === 'paid' && $session->status === 'complete') {
                    // Webhook hasn\'t fired yet. We could promote here, but
                    // it\'s cleaner to let the webhook be the source of truth.
                    // Just report pending for now; the next poll should see it.
                }
                if ($session->status === 'expired') {
                    return response()->json(['ok' => true, 'status' => 'expired']);
                }
            } catch (\Throwable $e) {
                Log::warning('direct_payments.poll_failed', [
                    'tenant_id' => $tenant->id,
                    'session'   => $sale->checkout_session_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true, 'status' => 'pending']);
    }

    /**
     * MARKER-PATCH-172 — Cancel a pending Checkout-Session-backed sale.
     * Used when the operator closes the payment-link modal manually.
     */
    public function cancelCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->where('payment_status', 'unpaid')  // MARKER-PATCH-172C
            ->first();

        if (! $sale) {
            return response()->json(['ok' => true, 'status' => 'already_resolved']);
        }

        // Expire the Stripe session (best-effort) and mark the sale cancelled.
        if ($sale->checkout_session_id) {
            try {
                $direct = new DirectPaymentsService($tenant);
                $direct->retrieveCheckoutSession($sale->checkout_session_id); // ensure exists
                // Stripe Checkout sessions don\'t have a direct cancel API,
                // but expire is achievable via the expire endpoint.
                // Use stripe SDK's expire method.
                // NOTE: stripe-php exposes this as $client->checkout->sessions->expire($id)
                // (added in newer versions). If unavailable, the session will
                // simply expire after 24h naturally.
                // We swallow errors here — they're non-fatal.
                $client = new \Stripe\StripeClient(['api_key' => $tenant->settings['register_payments_' . ($tenant->settings['register_payments_mode'] ?? 'test') . '_sk'] ?? null]);
                try {
                    $client->checkout->sessions->expire($sale->checkout_session_id);
                } catch (\Throwable $_) {
                    // expire may not be available on older SDK versions — ignore.
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup.
                Log::info('direct_payments.session_expire_skipped', [
                    'tenant_id' => $tenant->id,
                    'session'   => $sale->checkout_session_id,
                ]);
            }
        }

        // MARKER-PATCH-172C — payment_status enum doesn't have 'cancelled'.
        // status column already has 'cancelled'. payment_status stays 'unpaid'
        // (customer didn't pay; was never going to from this aborted attempt).
        $sale->status = 'cancelled';
        $sale->save();

        return response()->json(['ok' => true, 'status' => 'cancelled']);
    }

    /**
     * MARKER-PATCH-173 — Customer-facing landing page after a successful
     * Stripe Checkout payment (send-payment-link flow). PUBLIC route: the
     * paying customer is anonymous on their own device. Tenant is resolved by
     * ResolveTenant middleware and $currentTenant is shared to the view.
     *
     * No money depends on this page — the webhook has already promoted the
     * sale to paid by the time Stripe redirects here. total_cents is set on
     * the draft sale at link-creation time, so we show the amount without any
     * synchronous Stripe round-trip.
     */
    public function checkoutSuccess(Request $request)
    {
        $tenant    = tenant();
        $sessionId = (string) $request->query('session_id', '');

        $sale = null;
        if ($sessionId !== '') {
            $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->where('checkout_session_id', $sessionId)
                ->first();
        }

        return view('tenant.register.checkout-success', [
            'amountCents' => $sale?->total_cents,
        ]);
    }

    /**
     * MARKER-PATCH-173 — Customer-facing landing page when the customer backs
     * out of the Stripe Checkout page. Nothing was charged. PUBLIC route.
     */
    public function checkoutCancel(Request $request)
    {
        return view('tenant.register.checkout-cancel');
    }

    /**
     * MARKER-PATCH-170B — auto-refund a PaymentIntent. Called by the client
     * when commitTransaction fails after a charge already authorized.
     *
     * Idempotent: if the PI was already refunded, Stripe returns the existing
     * refund instead of erroring.
     */
    public function autoRefundPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'payment_intent' => 'required|string',
            'reason'         => 'nullable|string|max:255',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        $refund = $direct->refundPaymentIntent(
            $validated['payment_intent'],
            $validated['reason'] ?? 'sale_commit_failed'
        );

        if (! $refund) {
            return response()->json([
                'ok'    => false,
                'error' => 'Refund failed. Charge may still be live in Stripe — check the Stripe dashboard.',
            ], 500);
        }

        return response()->json([
            'ok'        => true,
            'refund_id' => $refund->id,
            'amount'    => $refund->amount,
        ]);
    }

    public function storeRefund(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'original_sale_id' => 'required|uuid',
            'refund_method'    => 'required|string|in:cash,card,check,store_credit,mark_paid',
            'reason'           => 'nullable|string|max:500',
            'notes'            => 'nullable|string',
            'item_ids'         => 'required|array|min:1',
            'item_ids.*'       => 'uuid',
        ]);

        try {
            $refund = $this->sales->createRefund([
                'tenant_id'          => $tenant->id,
                'original_sale_id'   => $validated['original_sale_id'],
                'rang_up_by_user_id' => auth('tenant')->id(),
                'refund_method'      => $validated['refund_method'],
                'reason'             => $validated['reason'] ?? null,
                'notes'              => $validated['notes'] ?? null,
                'item_ids'           => $validated['item_ids'],
            ]);

            // MARKER-PATCH-171 — fire a Stripe refund when the refund is to card
            // AND the original sale was paid via direct-payments Stripe flow.
            // Failure here is REPORTED but doesn\'t roll back the Intake refund row —
            // the operator can retry from sale detail (or via Stripe dashboard).
            $stripeRefundError = null;
            if ($validated['refund_method'] === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }

            return response()->json([
                'ok'                  => true,
                'refund_id'           => $refund->id,
                'sale_number'         => $refund->sale_number,
                'total_cents'         => $refund->total_cents,
                'stripe_refund_error' => $stripeRefundError,
                'stripe_refund_id'    => $refund->fresh()->stripe_refund_id,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * MARKER-PATCH-171 — shared helper to fire a Stripe refund for a refund row.
     * Returns null on success, or an error message string on failure.
     *
     * The refund row\'s own stripe_payment_intent_id is copied from the original
     * sale in SaleService::createRefund via the existing snapshot machinery —
     * but to be safe and explicit, we read the original sale fresh from the DB
     * and use ITS stripe_payment_intent_id (the refund row may not have one set
     * depending on snapshot config).
     */
    protected function fireStripeRefund(\App\Models\Tenant $tenant, \App\Models\Tenant\TenantSale $refundRow): ?string
    {
        if (! $tenant->direct_payments_enabled) {
            // Not an error — just nothing to do.
            return null;
        }

        $original = \App\Models\Tenant\TenantSale::find($refundRow->refund_of_sale_id);
        if (! $original || ! $original->stripe_payment_intent_id) {
            // Original wasn\'t paid via Stripe; nothing to refund there.
            return null;
        }

        try {
            $direct = new DirectPaymentsService($tenant);
            $stripeRefund = $direct->refundCharge(
                $original->stripe_payment_intent_id,
                (int) $refundRow->total_cents,
                [
                    'intake_refund_sale_id'   => $refundRow->id,
                    'intake_original_sale_id' => $original->id,
                ]
            );
            $refundRow->stripe_refund_id = $stripeRefund->id;
            $refundRow->save();
            return null;
        } catch (\Throwable $e) {
            Log::error('direct_payments.refund_failed', [
                'tenant_id'        => $tenant->id,
                'refund_sale_id'   => $refundRow->id,
                'original_sale_id' => $original->id,
                'pi'               => $original->stripe_payment_intent_id,
                'error'            => $e->getMessage(),
            ]);
            return $e->getMessage();
        }
    }

    /**
     * MARKER-PATCH-461 — Record an overage refund against an appointment.
     *
     * paid_cents > total_cents means the customer overpaid. This returns the
     * difference (or a portion of it): it writes a negative overage_refund row
     * on the appointment's money-bearing sale, which cascades the appointment
     * paid cache + payment_status centrally via SalePaymentService::recalcStatus().
     * 'card' fires a real Stripe partial refund when the sale has a charge to
     * reverse; otherwise card records a manual refund like the other methods.
     */
    public function recordAppointmentOverageRefund(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'appointment_id' => 'required|uuid',
            'amount_cents'   => 'required|integer|min:1',
            'method'         => 'required|string|in:cash,check,store_credit,mark_paid,card',
            'notes'          => 'nullable|string|max:500',
        ]);

        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $validated['appointment_id'])
            ->first();
        if (! $appointment) {
            return response()->json(['ok' => false, 'error' => 'Appointment not found.'], 404);
        }

        // Authoritative overage from the ledger, not the cached column.
        $appointment->load('payments');
        $paid    = $appointment->paidCentsFromLedger();
        $total   = (int) $appointment->total_cents;
        $overage = $paid - $total;

        if ($overage <= 0) {
            return response()->json(['ok' => false, 'error' => 'This appointment has no overage to refund.'], 422);
        }
        if ((int) $validated['amount_cents'] > $overage) {
            return response()->json([
                'ok'    => false,
                'error' => 'Refund exceeds the overage of $' . number_format($overage / 100, 2) . '.',
            ], 422);
        }

        // The sale holding the money: highest net-paid, non-cancelled.
        $paymentSvc = app(\App\Services\Tenant\SalePaymentService::class);
        $sale = $appointment->sales()
            ->where('status', '!=', 'cancelled')
            ->get()
            ->sortByDesc(fn ($s) => $paymentSvc->paidCents($s))
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'No sale found to refund against.'], 422);
        }

        // MARKER-PATCH-462 — card refund: fire a real Stripe refund when the sale
        // has a charge to reverse; otherwise fall through and record a manual
        // 'card' refund (operator returned it out-of-band — terminal, manual key).
        $stripeRefundId = null;
        if ($validated['method'] === 'card'
            && $tenant->direct_payments_enabled
            && $sale->stripe_payment_intent_id) {
            try {
                $direct = new DirectPaymentsService($tenant);
                $stripeRefund = $direct->refundCharge(
                    $sale->stripe_payment_intent_id,
                    (int) $validated['amount_cents'],
                    [
                        'intake_overage_refund_appointment_id' => $appointment->id,
                        'intake_sale_id'                       => $sale->id,
                    ]
                );
                $stripeRefundId = $stripeRefund->id;
            } catch (\Throwable $e) {
                Log::error('overage_refund.stripe_failed', [
                    'tenant_id'      => $tenant->id,
                    'appointment_id' => $appointment->id,
                    'sale_id'        => $sale->id,
                    'error'          => $e->getMessage(),
                ]);
                return response()->json(['ok' => false, 'error' => 'Stripe refund failed: ' . $e->getMessage()], 422);
            }
        }

        // Refund chain: point at the most recent inbound payment on this sale.
        $referencePaymentId = \App\Models\Tenant\TenantSalePayment::where('sale_id', $sale->id)
            ->where('amount_cents', '>', 0)
            ->orderByDesc('recorded_at')
            ->value('id');

        try {
            $payment = $paymentSvc->record(
                $sale,
                -1 * (int) $validated['amount_cents'],
                \App\Models\Tenant\TenantSalePayment::KIND_OVERAGE_REFUND,
                \App\Models\Tenant\TenantSalePayment::SOURCE_MANUAL_ENTRY,
                $validated['method'],
                $referencePaymentId,
                $stripeRefundId,
                $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('overage_refund.record_failed', [
                'tenant_id'        => $tenant->id,
                'appointment_id'   => $appointment->id,
                'sale_id'          => $sale->id,
                'stripe_refund_id' => $stripeRefundId,
                'error'            => $e->getMessage(),
            ]);
            $tail = $stripeRefundId
                ? ' (Stripe refund ' . $stripeRefundId . ' DID fire — reconcile manually)'
                : '';
            return response()->json(['ok' => false, 'error' => 'Could not record the refund' . $tail . ': ' . $e->getMessage()], 500);
        }

        $appointment->refresh()->load('payments');
        $newPaid    = $appointment->paidCentsFromLedger();
        $newOverage = max(0, $newPaid - $total);

        Log::info('overage_refund.recorded', [
            'tenant_id'      => $tenant->id,
            'appointment_id' => $appointment->id,
            'sale_id'        => $sale->id,
            'amount_cents'   => (int) $validated['amount_cents'],
            'method'         => $validated['method'],
            'stripe_refund'  => $stripeRefundId,
            'by'             => auth('tenant')->id(),
        ]);

        return response()->json([
            'ok'               => true,
            'payment_id'       => $payment->id,
            'paid_cents'       => $newPaid,
            'overage_cents'    => $newOverage,
            'stripe_refund_id' => $stripeRefundId,
        ]);
    }


    /**
     * MARKER-PATCH-630 — allowed payment_method values: built-ins plus any
     * enabled manual method keys from tenant_payment_methods.
     */
    protected function allowedTenders(array $extra = []): string
    {
        $manual = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', tenant()->id)
            ->where('enabled', true)->where('kind', 'manual')
            ->pluck('method_key')->all();
        $all = array_unique(array_merge(['cash', 'card', 'check', 'store_credit', 'mark_paid', 'split'], $extra, $manual));
        return 'required|string|in:' . implode(',', $all);
    }

    protected function taxLabel($tenant): string
    {
        $rate = $tenant->default_tax_rate;
        if ($rate === null || (float) $rate === 0.0) {
            return 'No tax configured';
        }
        return 'Tax · ' . rtrim(rtrim(number_format((float) $rate, 3), '0'), '.') . '%';
    }

    /**
     * MARKER-PATCH-197 — Stripe-vs-ledger reconciliation report. Lists succeeded
     * Stripe payments with no matching ledger row ("paid in Stripe, unpaid in
     * Intake"). The safety net for any payment that slips past the webhook.
     */
    public function reconciliation(\Illuminate\Http\Request $request)
    {
        $tenant = tenant();
        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 90));

        $svc = new \App\Services\Tenant\PaymentReconciliationService($tenant);
        $result = $svc->unmatchedPayments($days);

        return view('tenant.register.reconciliation', [
            'days'      => $days,
            'scanned'   => $result['scanned'],
            'unmatched' => $result['unmatched'],
            'error'     => $result['error'],
        ]);
    }

    /**
     * MARKER-PATCH-197 — Reconcile a stranded Stripe payment by recording it
     * against a sale through the ledger. Requires an explicit sale_id (the
     * operator picks the candidate) and the PI id. Idempotent: refuses if a
     * ledger row for this PI already exists.
     */
    public function reconcilePayment(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'payment_intent' => 'required|string',
            'sale_id'        => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Idempotency: never double-record a PI.
        $already = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('external_reference', $validated['payment_intent'])
            ->exists();
        if ($already) {
            return response()->json(['ok' => false, 'error' => 'This payment is already recorded in the ledger.'], 409);
        }

        // Verify the PI with Stripe before recording — don't trust the request.
        $direct = new DirectPaymentsService($tenant);
        try {
            $pi = $direct->retrievePaymentIntent($validated['payment_intent']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not verify payment with Stripe.'], 422);
        }
        if (($pi->status ?? null) !== 'succeeded' || (int) ($pi->amount_received ?? 0) <= 0) {
            return response()->json(['ok' => false, 'error' => 'That Stripe payment is not a completed charge.'], 422);
        }

        $amountCents = (int) $pi->amount_received;
        $details = [];
        try { $details = $direct->extractCardDetails($pi); } catch (\Throwable $e) {}

        try {
            $hasPrior = $sale->payments()->count() > 0;
            app(\App\Services\Tenant\SalePaymentService::class)->record(
                sale:               $sale,
                amountCents:        $amountCents,
                kind:               $hasPrior
                    ? \App\Models\Tenant\TenantSalePayment::KIND_BALANCE
                    : ($sale->appointment_id
                        ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                        : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT),
                source:             \App\Models\Tenant\TenantSalePayment::SOURCE_DIRECT_PAYMENT_LINK,
                method:             'card',
                externalReference:  $pi->id,
                notes:              'Reconciled from Stripe (was not recorded by webhook).',
            );

            // Stamp the sale's Stripe fields + un-cancel if it was cancelled.
            $sale->stripe_payment_intent_id = $pi->id;
            if (!empty($details['charge_id'])) $sale->stripe_charge_id = $details['charge_id'];
            if (!empty($details['brand']))     $sale->card_brand       = $details['brand'];
            if (!empty($details['last4']))     $sale->card_last4       = $details['last4'];
            if (!empty($details['funding']))   $sale->card_funding     = $details['funding'];
            if ($sale->status === 'cancelled') $sale->status = 'completed';
            $sale->save();

            // MARKER-PATCH-219C — appointment paid cache cascades
            // centrally in SalePaymentService::recalcStatus().
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not record the payment: ' . $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'amount_cents' => $amountCents, 'sale_number' => $sale->sale_number]);
    }

    /**
     * MARKER-PATCH-198 — Hard-delete a single payment row from a sale's ledger.
     * For correcting bad data (e.g. a duplicate deposit). After deletion the
     * sale's payment_status + paid_at and the linked appointment's paid_cents
     * are recomputed so totals stay consistent. Double-confirmed in the UI.
     */
    public function deleteSalePayment(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id'    => 'required|uuid',
            'payment_id' => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        $payment = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('id', $validated['payment_id'])
            ->where('sale_id', $sale->id)
            ->first();
        if (! $payment) {
            return response()->json(['ok' => false, 'error' => 'Payment not found on this sale.'], 404);
        }

        $deletedCents = (int) $payment->amount_cents;
        $payment->delete();

        // Recompute the sale's derived payment state from the remaining ledger.
        $svc = app(\App\Services\Tenant\SalePaymentService::class);
        $svc->recalcStatus($sale);
        $sale->refresh();

        // MARKER-PATCH-219C — appointment paid cache cascades centrally in
        // SalePaymentService::recalcStatus() (called via recalcStatus above).

        \Illuminate\Support\Facades\Log::info('sale_payment.deleted', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'payment_id' => $validated['payment_id'],
            'amount'     => $deletedCents,
            'by'         => auth('tenant')->id(),
        ]);

        return response()->json([
            'ok'             => true,
            'paid_cents'     => $svc->paidCents($sale),
            'payment_status' => $sale->payment_status,
        ]);
    }

    /**
     * MARKER-PATCH-199 — Delete an empty sale (data correction for stray
     * deposit-sales left after their payment was removed). REFUSES if the sale
     * still has any ledger payments — you must clear those first (patch-198).
     * Hard-deletes the sale + its line items, then refreshes the linked
     * appointment's paid cache. Double-confirmed in the UI.
     */
    public function deleteSale(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Guard: never delete a sale that still carries money. The operator must
        // remove the payments first (so the deletion can't silently lose a
        // recorded payment).
        $payCount = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->count();
        if ($payCount > 0) {
            return response()->json([
                'ok'    => false,
                'error' => 'This sale still has ' . $payCount . ' payment(s). Delete those first, then delete the sale.',
            ], 409);
        }

        // Guard: never delete a refund record this way.
        if ($sale->refund_of_sale_id) {
            return response()->json(['ok' => false, 'error' => 'Refund records cannot be deleted here.'], 422);
        }

        $apptId = $sale->appointment_id;

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $sale) {
            \App\Models\Tenant\TenantSaleItem::where('tenant_id', $tenant->id)
                ->where('sale_id', $sale->id)
                ->delete();
            $sale->delete();
        });

        // Recompute the linked appointment's paid cache from the remaining ledger.
        // MARKER-PATCH-219C — this block stays MANUAL by necessity: the sale
        // row was just deleted, so SalePaymentService::recalcStatus() can
        // never run for it. Every other site cascades centrally.
        if ($apptId) {
            $appt = \App\Models\Tenant\TenantAppointment::find($apptId);
            if ($appt) {
                $appt->paid_cents = (int) $appt->payments()->sum('tenant_sale_payments.amount_cents');
                $total = (int) $appt->total_cents;
                if ($total > 0 && $appt->paid_cents >= $total) {
                    $appt->payment_status = ($appt->paid_cents > $total) ? 'overage' : 'paid';
                } elseif ($appt->paid_cents > 0) {
                    $appt->payment_status = 'partial';
                } else {
                    $appt->payment_status = 'unpaid';
                }
                $appt->save();
            }
        }

        \Illuminate\Support\Facades\Log::info('sale.deleted', [
            'tenant_id'   => $tenant->id,
            'sale_id'     => $validated['sale_id'],
            'sale_number' => $sale->sale_number,
            'by'          => auth('tenant')->id(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-OFFLINE-SYNC — catalog snapshot for offline register search.
     * Top products by 90-day sale frequency plus all active services, shaped
     * like the live /register/search response so the offline path reuses
     * renderResults() unchanged.
     */
    public function offlineCatalog(Request $request): JsonResponse
    {
        $tenant = app('tenant');

        $topProductIds = \Illuminate\Support\Facades\DB::table('tenant_sale_items')
            ->join('tenant_sales', 'tenant_sales.id', '=', 'tenant_sale_items.sale_id')
            ->where('tenant_sales.tenant_id', $tenant->id)
            ->where('tenant_sales.sale_date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('tenant_sale_items.inventory_item_id')
            ->groupBy('tenant_sale_items.inventory_item_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(min(1000, max(100, (int) $request->query('limit', 500)))) // MARKER-OFFLINE-SYNC stage 2
            ->pluck('tenant_sale_items.inventory_item_id')
            ->all();

        $products = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $topProductIds)
            ->get()
            ->map(fn ($p) => [
                'id'                     => $p->id,
                'name'                   => $p->name ?? '',
                'subtitle'               => $p->display_subtitle ?? '',
                'sku'                    => $p->sku ?? '',
                'price_cents'            => (int) ($p->effectiveSellPriceCents() ?? 0),
                'is_taxable'             => (($p->tax_class_code ?? null) !== 'exempt'),
                'allow_oversell'         => (bool) $p->allow_oversell,
                'current_location_stock' => 0,
                'current_location_name'  => null,
            ])->values();

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', 1)
            ->get()
            ->map(fn ($sv) => [
                'id'               => $sv->id,
                'name'             => $sv->name,
                'price_cents'      => (int) ($sv->price_cents ?? 0),
                'duration_minutes' => (int) ($sv->duration_minutes ?? 0),
            ])->values();

        return response()->json([
            'ok'          => true,
            'captured_at' => now()->toIso8601String(),
            'products'    => $products,
            'services'    => $services,
        ]);
    }
}
REFQTY_3_EOF

cat > 'resources/views/tenant/register/index.blade.php' <<'REFQTY_4_EOF'
@extends('layouts.tenant.app')

@php $pageTitle = 'Register'; @endphp

@push('styles')
<style>
  .reg-page { --reg-danger: #F09595; --reg-danger-bg: rgba(226,75,74,.15); }

  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-OFFLINE-SYNC stage 3b — mobile: picker on its own full-width row
     instead of floating beside wrapped tabs */
  @media (max-width: 760px) {
    .reg-tabs-bar #registerPicker{
      order:99;flex:1 1 100%;max-width:none;margin:8px 0 2px;width:100%;
    }
    .reg-tabs-bar{row-gap:2px}
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  /* patch-96 layout — 50/50 split between item search and cart */
  .reg-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:18px;
  }
  @media(max-width:1200px){ .reg-grid{grid-template-columns:1fr} }

  /* patch-100a oversell-actions — action row below oversold cart lines */
  .reg-oversell-actions {
    display: flex; gap: 8px; align-items: center;
    margin-top: 6px; flex-wrap: wrap;
  }
  .reg-oversell-btn {
    font-size: 11px; padding: 3px 10px;
    background: transparent;
    color: var(--ia-text);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-xs);
    cursor: pointer; transition: background 120ms ease;
  }
  .reg-oversell-btn:hover { background: var(--ia-hover); }
  .reg-oversell-pill {
    display: inline-block;
    font-size: 11px; padding: 3px 10px;
    background: rgba(99,153,34,0.12);
    color: #639922;
    border: 0.5px solid rgba(99,153,34,0.35);
    border-radius: var(--ia-r-xs);
    font-weight: 500;
  }
  /* patch-96 oversell-badge — small amber inline marker on cart lines */
  .reg-oversell-badge {
    display:inline-block; margin-left:8px;
    padding:2px 7px;
    background:rgba(245,158,11,0.12);
    color:#F59E0B;
    border:0.5px solid rgba(245,158,11,0.35);
    border-radius:var(--ia-r-xs);
    font-size:10.5px; font-weight:600;
    letter-spacing:0.02em;
    vertical-align:middle;
    white-space:nowrap;
  }
  .reg-panel{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);padding:18px
  }

  .reg-search{
    width:100%;padding:12px 14px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);
    color:var(--ia-text);font-size:14px;font-family:inherit
  }
  .reg-search:focus{outline:none;border-color:var(--ia-accent)}

  .reg-tabs{display:flex;gap:6px;margin:12px 0 14px}
  .reg-tab{
    padding:6px 12px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:99px;color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer
  }
  .reg-tab.active{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}

  .reg-results-section{margin-top:14px}
  .reg-results-section h3{
    font-size:11px;font-weight:600;color:var(--ia-text-dim);
    text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px
  }
  .reg-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 12px;border-radius:var(--ia-r-md);cursor:pointer;transition:background var(--ia-t)
  }
  .reg-row:hover{background:var(--ia-hover)}
  .reg-row.highlighted{background:var(--ia-hover)}
  .reg-results-section.mouse-active .reg-row.highlighted:not(:hover){background:transparent}
  .reg-row .name{font-weight:500;font-size:14px}
  .reg-row .meta{font-size:12px;color:var(--ia-text-dim)}
  .reg-row .price{font-size:14px;font-weight:600;color:var(--ia-text);white-space:nowrap}

  .reg-hint{
    display:flex;gap:14px;align-items:center;
    font-size:11px;color:var(--ia-text-dim);
    margin:8px 4px 6px;padding:0 4px
  }
  .reg-hint kbd{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;padding:0 5px;
    background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);
    font-family:var(--ia-font-mono);
    font-size:10px;color:var(--ia-text-muted);
    margin:0 2px
  }

  .reg-open-item{
    width:100%;margin-top:10px;padding:10px 14px;
    background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);
    font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-open-item:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-cust{
    display:flex;flex-direction:column;gap:6px;
    padding:12px 14px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);
    margin-bottom:14px;font-size:13px
  }
  .reg-cust .head{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .reg-cust .name{font-weight:500;font-size:14px}
  .reg-cust .meta{display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--ia-text-dim)}
  .reg-cust .meta a{color:var(--ia-text-dim);text-decoration:none}
  .reg-cust .meta a:hover{color:var(--ia-text);text-decoration:underline}
  .reg-cust .actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px;padding-top:8px;border-top:0.5px solid var(--ia-border)}
  .reg-cust .profile-link{font-size:12px;color:var(--ia-accent);text-decoration:none}
  .reg-cust .profile-link:hover{text-decoration:underline}
  .reg-cust .clear{color:var(--ia-text-dim);cursor:pointer;font-size:11px}
  .reg-cust .clear:hover{color:var(--reg-danger)}

  .reg-attach{
    width:100%;padding:10px;background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);font-size:13px;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);margin-bottom:14px
  }
  .reg-attach:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-lines{
    max-height:340px;overflow-y:auto;margin:0 -4px 14px;padding:0 4px;
    border-bottom:0.5px solid var(--ia-border);padding-bottom:14px
  }
  .reg-line{
    display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 4px
  }
  .reg-line .name{font-size:13px;font-weight:500;line-height:1.3}
  .reg-line .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-line .qty{
    width:50px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-line .qty:focus{outline:none;border-color:var(--ia-accent)}
  .reg-line .total{font-size:13px;font-weight:600;text-align:right;min-width:62px}
  .reg-line .remove{background:transparent;border:none;color:var(--ia-text-dim);font-size:16px;cursor:pointer;padding:0 4px;line-height:1}
  .reg-line .remove:hover{color:var(--reg-danger)}
  .reg-empty{padding:30px 0;text-align:center;color:var(--ia-text-dim);font-size:13px}

  .reg-totals{font-size:13px}
  .reg-totals-row{display:flex;justify-content:space-between;padding:5px 0;color:var(--ia-text-muted)}
  .reg-totals-row.grand{font-size:18px;font-weight:600;color:var(--ia-text);padding-top:10px;margin-top:6px;border-top:0.5px solid var(--ia-border)}

  .reg-pay-row{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-top:16px}
  .reg-pay{
    padding:14px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;
    font-family:inherit;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-pay:hover:not(:disabled){filter:brightness(.93)}
  .reg-pay:disabled{opacity:.4;cursor:not-allowed}

  .reg-quote-btn{
    padding:14px;background:rgba(var(--ia-accent-rgb,255,255,255),.10);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:14px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-quote-btn:hover:not(:disabled){border-color:var(--ia-accent);background:var(--ia-accent-soft)}
  .reg-quote-btn:disabled{opacity:.4;cursor:not-allowed}

  .reg-cust.warning{
    background:var(--reg-danger-bg);
    border:0.5px solid var(--reg-danger);
  }
  /* MARKER-PATCH-161 — receipt indicator */
  .reg-cust-receipt{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    margin-top:8px;
    background:rgba(190,242,100,.06);
    border:0.5px solid rgba(190,242,100,.2);
    border-radius:var(--ia-r-sm);
    font-size:12px;
    flex-wrap:wrap;
  }
  .reg-cust-receipt--none{
    background:var(--ia-surface);
    border-color:var(--ia-border);
    color:var(--ia-text-dim);
  }
  .reg-cust-receipt-status{display:flex;align-items:center;gap:8px;min-width:0;flex:1}
  .reg-cust-receipt-dot{
    width:8px;height:8px;border-radius:50%;background:var(--ia-accent);
    box-shadow:0 0 0 3px rgba(190,242,100,.15);flex-shrink:0;
  }
  .reg-cust-receipt-skip{
    display:flex;align-items:center;gap:6px;cursor:pointer;
    font-size:11.5px;color:var(--ia-text-dim);user-select:none;flex-shrink:0;
  }
  .reg-cust-receipt-skip input{width:14px;height:14px;accent-color:var(--ia-accent)}

  .reg-attach.warning{
    border:0.5px dashed var(--reg-danger);
    color:var(--reg-danger)
  }

  .reg-err{background:var(--reg-danger-bg);color:var(--reg-danger);border-radius:var(--ia-r-sm);padding:10px 12px;font-size:13px;margin-bottom:12px;border:0.5px solid rgba(248,113,113,.30)}
  /* MARKER-PATCH-170C — shake animation for errors. Triggered by toggling .reg-err--shake. */
  @keyframes reg-shake {
    0%,100% { transform: translateX(0); }
    15%     { transform: translateX(-6px); }
    30%     { transform: translateX(5px); }
    45%     { transform: translateX(-4px); }
    60%     { transform: translateX(3px); }
    75%     { transform: translateX(-2px); }
    90%     { transform: translateX(1px); }
  }
  .reg-err--shake { animation: reg-shake 0.55s ease-out; }

  /* Pre-flight blocker modal — uses the same surfaces as other reg-modals
     but with a danger-tinged accent on the title. */
  .reg-preflight-icon {
    width: 44px; height: 44px;
    background: rgba(248,113,113,.10);
    border: 0.5px solid rgba(248,113,113,.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #f87171;
    margin: 0 auto 14px;
  }
  .reg-preflight h2 { text-align: center; }
  .reg-preflight .lede { text-align: center; }

  .reg-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:420px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}

  .reg-tender-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
  .reg-tender-btn{
    padding:14px 12px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);text-align:left
  }
  .reg-tender-btn:hover{border-color:var(--ia-accent)}
  /* MARKER-SPLIT-TENDER */
  .reg-split-remaining{display:flex;justify-content:space-between;font-size:13.5px;padding:8px 2px;border-bottom:1px solid var(--ia-border);margin-bottom:10px}
  .reg-split-remaining b{font-variant-numeric:tabular-nums;color:#F5C56B}
  .reg-split-remaining.zero b{color:var(--ia-accent)}
  .reg-split-row{display:flex;align-items:center;gap:10px;border:1px solid var(--ia-border);border-radius:10px;padding:9px 12px;margin-bottom:7px;font-size:13px;flex-wrap:wrap}
  .reg-split-row .amt{margin-left:auto;font-weight:700;font-variant-numeric:tabular-nums}
  .reg-split-row .x{color:var(--ia-text-dim);cursor:pointer;padding:2px 6px;border-radius:6px}
  .reg-split-row .x:hover{color:#F09595}
  .reg-split-row .chg{flex-basis:100%;font-size:11px;color:var(--ia-accent)}
  .reg-tender-btn.split-disabled{opacity:.35;pointer-events:none}
  .reg-tender-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:12px 0}
  .reg-tip-btn{
    padding:12px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-tip-btn:hover{border-color:var(--ia-accent)}
  .reg-tip-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-custom{display:flex;gap:8px;align-items:center;margin-top:6px}
  .reg-tip-custom input{flex:1;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-tip-custom input:focus{outline:none;border-color:var(--ia-accent)}

  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{flex:1;padding:11px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;transition:all var(--ia-t)}
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:filter var(--ia-t)}
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}

  .reg-modal input[type=text]{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-modal input[type=text]:focus{outline:none;border-color:var(--ia-accent)}

  .reg-receipt{text-align:center}
  .reg-receipt h2{font-size:24px;margin-bottom:6px}
  .reg-receipt .num{font-size:13px;color:var(--ia-text-dim);margin-bottom:18px;font-family:var(--ia-font-mono)}
  .reg-receipt .total{font-size:36px;font-weight:700;margin:14px 0}
  /* MARKER-PATCH-187 — auto-reset countdown line */
  .reg-receipt-auto{margin-top:14px;font-size:12px;color:var(--ia-text-dim)}
  .reg-receipt-auto span{font-variant-numeric:tabular-nums;color:var(--ia-text)}

  .reg-cust-results{position:absolute;top:100%;left:0;right:0;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);margin-top:4px;max-height:240px;overflow-y:auto;z-index:10}

  .reg-refund-result{
    padding:12px;background:rgba(190,242,100,.04);
    border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);
    margin-bottom:10px;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-refund-result:hover{filter:brightness(1.1)}
  .reg-refund-result .label{font-size:11px;color:var(--ia-accent);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:4px}
  .reg-refund-result .name{font-size:14px;font-weight:500}
  .reg-refund-result .meta{font-size:12px;color:var(--ia-text-dim);margin-top:2px}

  .reg-refund-list{max-height:380px;overflow-y:auto;margin:-4px 0 14px;padding:4px 0}
  .reg-refund-row{
    display:grid;grid-template-columns:auto 1fr auto auto;gap:12px;align-items:center;
    padding:10px 12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);
    margin-bottom:6px
  }
  .reg-refund-row.disabled{opacity:.4}
  .reg-refund-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--ia-accent)}
  .reg-refund-row .name{font-size:13px;font-weight:500}
  .reg-refund-row .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-refund-row .qty-input{
    width:60px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-refund-row .qty-input:focus{outline:none;border-color:var(--ia-accent)}
  .reg-refund-row .qty-input:disabled{opacity:.4;cursor:not-allowed}
  .reg-refund-row .total{font-size:13px;font-weight:600;text-align:right;min-width:70px}

  .reg-cart-section-label{
    font-size:10px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.08em;
    font-weight:600;padding:8px 4px 4px;border-top:0.5px solid var(--ia-border);
    margin-top:8px
  }
  .reg-cart-section-label:first-child{border-top:none;margin-top:0}
  .reg-cart-section-label.refund{color:#F09595}

  .reg-line.refund-line{background:rgba(226,75,74,.04)}
  .reg-line.refund-line .total{color:#F09595}
  .reg-line.refund-line .meta{color:#F09595;opacity:.7}
  .reg-cust-results .row{padding:10px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border)}
  .reg-cust-results .row:hover{background:var(--ia-hover)}
  .reg-cust-results .row:last-child{border-bottom:none}

  .reg-drafts-banner{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:11px 14px 11px 13px;
    background:var(--ia-accent-soft);
    border:0.5px solid var(--ia-border);
    border-left:3px solid var(--ia-accent);
    border-radius:var(--ia-r-md);margin-bottom:14px;font-size:13px;cursor:pointer;
    transition:filter var(--ia-t)
  }
  .reg-drafts-banner:hover{filter:brightness(1.08)}
  .reg-drafts-banner .label{color:var(--ia-text);font-weight:500}
  .reg-drafts-banner .cta{font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;font-weight:500}

  .reg-save-status{
    font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;
    margin-bottom:8px;height:14px;line-height:14px;
    transition:opacity var(--ia-t);opacity:0
  }
  .reg-save-status.visible{opacity:1}

  .reg-drafts-list{max-height:380px;overflow-y:auto;margin:-4px -4px 14px;padding:4px}
  .reg-draft-row{
    display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;
    padding:12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-bottom:8px
  }
  .reg-draft-row .meta-line{font-size:12px;color:var(--ia-text-dim);margin-top:2px}
  .reg-draft-row .total{font-size:14px;font-weight:600;text-align:right;min-width:62px}
  .reg-draft-row .actions{display:flex;gap:6px}
  .reg-draft-row .btn-resume{padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-resume:hover{filter:brightness(.93)}
  .reg-draft-row .btn-discard{padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-discard:hover{color:var(--reg-danger);border-color:var(--reg-danger)}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Register</h1>
    <p class="ia-page-subtitle">Walk-in sales and retail checkouts.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a> {{-- MARKER-REGISTER-RECON-DISPLAY --}}
  {{-- MARKER-REGISTER-RECON-DISPLAY — register picker (only when registers exist) --}}
  @if (($registers ?? collect())->isNotEmpty())
    <select id="registerPicker" class="ia-input" style="margin-left:auto;max-width:220px;font-size:13px"
            title="Pay-station display this device drives">
      <option value="0">No register / display</option>
      @foreach ($registers as $r)
        <option value="{{ $r->id }}" @selected(($currentRegisterId ?? 0) === $r->id)>#{{ $r->number }} — {{ $r->name }}</option>
      @endforeach
    </select>
  @endif
</div>

@if(($appointmentTrayCount ?? 0) > 0)
  {{-- Appointment-sourced sales waiting for payment. Auto-created when staff
       marked an appointment Completed. We surface them prominently so staff
       can't miss a parked sale. --}}
  <div id="appointment-tray-banner" style="background:rgba(21,112,205,.07);border:0.5px solid rgba(21,112,205,.30);border-radius:var(--ia-r-md);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div style="display:flex;align-items:center;gap:12px;flex:1">
      <span style="font-size:20px">💳</span>
      <div>
        <div style="font-weight:500;font-size:14px;color:var(--ia-text)">{{ $appointmentTrayCount }} {{ $appointmentTrayCount === 1 ? 'appointment is' : 'appointments are' }} ready for checkout</div>
        <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">From recently completed appointments. Click to take payment.</div>
      </div>
    </div>
    <button type="button" id="appointment-tray-toggle" class="ia-btn ia-btn--primary ia-btn--sm">View list</button>
  </div>
  <div id="appointment-tray-list" style="display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:8px;margin-bottom:18px"></div>
@endif

<div class="reg-page">

  <div class="reg-grid">

    <div class="reg-panel">
      <input type="text" class="reg-search" id="searchInput" placeholder="Search products and services…" autocomplete="off">

      <div class="reg-tabs">
        <button type="button" class="reg-tab active" data-type="all">All</button>
        <button type="button" class="reg-tab" data-type="product">Products</button>
        <button type="button" class="reg-tab" data-type="service">Services</button>
      </div>

      <div class="reg-hint" id="regHint" style="display:none">
        <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
        <span><kbd>↵</kbd> add</span>
        <span><kbd>esc</kbd> clear</span>
      </div>

      <div id="resultsArea">
        <div class="reg-empty">Type to search products and services.</div>
      </div>

      <button type="button" class="reg-open-item" id="addOpenItemBtn">+ Add custom item</button>
    </div>

    <div class="reg-panel">
      <div id="errBanner" class="reg-err" style="display:none"></div>

      <div id="saveStatus" class="reg-save-status"></div>

      <div id="draftsBanner" class="reg-drafts-banner" style="display:none">
        <span class="label" id="draftsBannerLabel"></span>
        <span class="cta">View →</span>
      </div>

      <div id="customerSlot">
        <button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>
      </div>

      <div class="reg-lines" id="cartLines">
        <div class="reg-empty">Cart is empty.</div>
      </div>

      <div class="reg-totals">
        <div class="reg-totals-row"><span>Subtotal</span><span id="subVal">$0.00</span></div>
        <div class="reg-totals-row" id="discountRow" style="display:none"><span>Discount</span><span id="discVal">-$0.00</span></div>
        <div class="reg-totals-row"><span>Tax</span><span id="taxVal">$0.00</span></div>
        <div class="reg-totals-row" id="surchargeRow" style="display:none"><span id="surchLabel">Surcharge</span><span id="surchVal">$0.00</span></div>
        <div class="reg-totals-row" id="tipRow" style="display:none"><span>Tip</span><span id="tipVal">$0.00</span></div>
        <div class="reg-totals-row grand"><span>Total</span><span id="totalVal">$0.00</span></div>
      </div>

      <div class="reg-pay-row">
        <button type="button" class="reg-quote-btn" id="quoteBtn" disabled>Save quote</button>
        <button type="button" class="reg-pay" id="payBtn" disabled>Collect payment</button>
      </div>
    </div>

  </div>

</div>

<div class="reg-modal-bg" id="refundTenderModal">
  <div class="reg-modal">
    <h2>Refund to customer</h2>
    <div class="lede" id="refundTenderLede">How is the refund being given?</div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-refund-tender="card">Refund to card</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="cash">Cash from drawer</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="store_credit">Store credit</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundTenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundTenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="tenderModal">
  <div class="reg-modal">
    <h2>Choose tender</h2>
    <div class="lede">How is the customer paying?</div>
    {{-- MARKER-SPLIT-TENDER — running remaining + recorded split payments --}}
    <div class="reg-split-remaining" id="splitRemainRow" style="display:none">
      <span>Remaining</span><b id="splitRemain"></b>
    </div>
    <div id="splitPayList"></div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-tender="cash">Cash</button>
      <button type="button" class="reg-tender-btn" data-tender="card">Card</button>
      {{-- MARKER-PATCH-172 — payment-link tender (hidden when direct payments off) --}}
      <button type="button" class="reg-tender-btn" data-tender="payment_link" id="tenderPaymentLinkBtn" style="display:none">
        Send payment link
        <div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">Customer pays from their phone</div>
      </button>
      <button type="button" class="reg-tender-btn" data-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-tender="store_credit">Store credit</button>
      {{-- MARKER-PATCH-630 — manual tenders from tenant_payment_methods (Venmo, Cash App, custom) --}}
      @foreach(($manualTenders ?? []) as $mt)
        <button type="button" class="reg-tender-btn" data-tender="{{ $mt['key'] }}"
                data-manual="1" data-name="{{ $mt['name'] }}"
                @if($mt['linktpl']) data-linktpl="{{ $mt['linktpl'] }}" @endif
                @if($mt['instructions']) data-instructions="{{ $mt['instructions'] }}" @endif>
          {{ $mt['name'] }}
          @if($mt['hint'])<div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">{{ $mt['hint'] }}</div>@endif
        </button>
      @endforeach
      <button type="button" class="reg-tender-btn" data-tender="mark_paid">No tender (already paid)</button>
    </div>
    {{-- MARKER-SPLIT-TENDER — amount entry: prefilled with remaining; Add
         records a partial payment. Untouched prefill + Confirm = the classic
         single-tender path, unchanged. --}}
    <div id="splitAmountRow" style="display:none;gap:8px;margin-bottom:12px">
      <div style="flex:1;position:relative">
        <b style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim);font-size:15px">$</b>
        <input type="text" id="splitAmountInput" inputmode="decimal"
               style="width:100%;padding:12px 12px 12px 26px;font-size:16px;font-weight:700;font-variant-numeric:tabular-nums">
      </div>
      <button type="button" class="reg-btn" id="splitAddBtn" style="padding:0 18px;font-weight:800">Add payment</button>
    </div>
    <div id="splitHint" style="display:none;font-size:11.5px;color:var(--ia-text-dim);margin:-6px 0 12px">
      Type a partial amount to split tenders — cash above the remainder computes change.
    </div>
    <div id="tenderRefRow" style="display:none;margin-bottom:14px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reference (optional)</label>
      <input type="text" id="tenderRefInput" placeholder="Check #, last 4 of card, etc.">
    </div>
    {{-- MARKER-PATCH-630 — manual payment link (Venmo / Cash App) --}}
    <div id="tenderManualRow" style="display:none;margin-bottom:14px">
      <div id="tenderManualInstr" style="font-size:12px;color:var(--ia-text-muted);margin-bottom:8px"></div>
      <div id="tenderManualLinkWrap" style="display:none">
        <div id="tenderManualLink" style="font-size:12px;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);border-radius:8px;padding:9px 11px;color:var(--ia-accent);word-break:break-all;margin-bottom:8px"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="reg-btn-secondary" id="tenderManualCopy" style="font-size:12px;padding:7px 13px">Copy link</button>
          <a class="reg-btn-secondary" id="tenderManualSms" style="font-size:12px;padding:7px 13px;text-decoration:none" href="#">Text to customer</a>
        </div>
      </div>
      <div style="font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.4));margin-top:8px">Confirm the payment arrived in your app, then continue — the sale records as paid by this method.</div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-170C — Pre-flight blocker modal. Shown when the Charge
     button is pressed but the cart isn't commit-able. Replaces hidden inline
     errors that were easy to miss. --}}
<div class="reg-modal-bg" id="preflightModal">
  <div class="reg-modal reg-preflight">
    <div class="reg-preflight-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <h2 id="preflightTitle">Add a customer</h2>
    <div class="lede" id="preflightLede">A customer is required when the sale includes a service.</div>
    <div class="reg-modal-actions" style="justify-content:center;gap:10px">
      <button type="button" class="reg-btn-secondary" data-close-modal="preflightModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="preflightActionBtn">Add customer →</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-170 — Direct Payments card-entry modal --}}
<div class="reg-modal-bg" id="cardPaymentModal">
  <div class="reg-modal">
    <h2>Card payment</h2>
    <div class="lede">Enter card details. Powered by Stripe.</div>

    <div id="cardPaymentSummary" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="cardPaymentAmount">$0.00</span></div>
    </div>

    <div id="card-payment-element" style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:16px;margin-bottom:14px;min-height:60px"></div>

    <div id="cardPaymentError" style="display:none;padding:12px 14px;background:rgba(248,113,113,.10);border:0.5px solid rgba(248,113,113,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:#f87171;margin-bottom:14px"></div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="cardPaymentCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="cardPaymentChargeBtn" disabled>
        <span id="cardPaymentChargeLabel">Charge</span>
        <span id="cardPaymentSpinner" style="display:none;margin-left:8px">…</span>
      </button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-172 — Send-payment-link modal --}}
<div class="reg-modal-bg" id="paymentLinkModal">
  <div class="reg-modal" style="max-width:520px">
    <h2>Send payment link</h2>
    <div class="lede">Share this link with your customer. They'll pay from their device.</div>

    <div id="paymentLinkAmount" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="paymentLinkAmountValue">$0.00</span></div>
    </div>

    <div id="paymentLinkQRContainer" style="background:white;border-radius:var(--ia-r-md);padding:18px;margin-bottom:14px;display:flex;justify-content:center;align-items:center;min-height:240px">
      <div id="paymentLinkQR"></div>
    </div>

    <div style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
      <code id="paymentLinkUrl" style="flex:1;font-size:11px;color:var(--ia-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></code>
      <button type="button" class="reg-btn-secondary" id="paymentLinkCopyBtn" style="padding:6px 10px;font-size:11.5px">Copy</button>
    </div>

    <div id="paymentLinkStatus" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:var(--ia-text);margin-bottom:14px">
      <span class="stripe-spinner" style="width:14px;height:14px;border:2px solid rgba(190,242,100,.2);border-top-color:var(--ia-accent);border-radius:50%;animation:spin 0.8s linear infinite"></span>
      <span id="paymentLinkStatusText">Waiting for customer to pay…</span>
    </div>

    {{-- MARKER-PATCH-192 — two distinct actions: "Done" keeps the link live
         (sale stays pending, trackable from the appointment); "Cancel link" is
         the explicit destructive action that expires the Stripe session. --}}
    <div class="reg-modal-actions" style="display:flex;gap:10px;justify-content:space-between">
      <button type="button" class="reg-btn-secondary" id="paymentLinkCancelBtn" style="color:var(--ia-red,#F87171)">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="paymentLinkDoneBtn">Done — keep link live</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
     banner (?status=<sale_id>) to show a live picture of an outstanding link. --}}
<div class="reg-modal-bg" id="linkStatusModal">
  <div class="reg-modal" style="max-width:560px">
    <h2 style="display:flex;align-items:center;gap:10px">Payment link status <span id="lsStatusPill" class="ls-pill"></span></h2>
    <div class="lede" id="lsHeader">Loading…</div>

    <div id="lsBody" style="margin-top:14px">
      <div class="ls-timeline" id="lsTimeline"></div>
    </div>

    <div class="ls-actions" id="lsActions" style="display:none;flex-direction:column;gap:8px;margin-top:16px">
      <div style="display:flex;gap:8px">
        <input type="text" id="lsUrl" readonly style="flex:1;font-size:11px;font-family:var(--ia-font-mono);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);padding:8px 10px;color:var(--ia-text-muted)">
        <button type="button" class="reg-btn-secondary" id="lsCopyBtn" style="padding:6px 12px;font-size:12px">Copy</button>
      </div>
    </div>

    <div class="reg-modal-actions" style="margin-top:18px;display:flex;justify-content:space-between;gap:10px">
      <button type="button" class="reg-btn-secondary" id="lsCancelLinkBtn" style="color:var(--ia-red,#F87171);display:none">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="lsCloseBtn" style="margin-left:auto">Close</button>
    </div>
  </div>
</div>

<style>
  .ls-pill{font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px}
  .ls-pill.pending{background:rgba(96,165,250,.12);color:#60A5FA}
  .ls-pill.paid{background:rgba(132,204,22,.12);color:#84CC16}
  .ls-pill.expired{background:rgba(251,191,36,.12);color:#FBBF24}
  .ls-timeline{position:relative;padding-left:22px}
  .ls-timeline:before{content:'';position:absolute;left:5px;top:6px;bottom:6px;width:1.5px;background:var(--ia-border)}
  .ls-te{position:relative;padding:7px 0}
  .ls-te:before{content:'';position:absolute;left:-21px;top:11px;width:9px;height:9px;border-radius:50%;background:var(--ia-surface);border:2px solid var(--ia-text-dim)}
  .ls-te.done:before{background:#84CC16;border-color:#84CC16}
  .ls-te.now:before{background:#60A5FA;border-color:#60A5FA}
  .ls-te .tt{font-size:13px;font-weight:500}
  .ls-te .td{font-size:11.5px;color:var(--ia-text-dim);font-family:var(--ia-font-mono);margin-top:1px}
</style>

<div class="reg-modal-bg" id="tipModal">
  <div class="reg-modal">
    <h2>Add tip?</h2>
    <div class="lede">Optional. Choose an amount or skip.</div>
    <div class="reg-tip-grid" id="tipGrid"></div>
    <div class="reg-tip-custom">
      <input type="text" id="tipCustomInput" placeholder="Custom amount">
      <button type="button" class="reg-btn-secondary" id="tipClearBtn" style="padding:10px 14px;flex:0">Clear</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="tipSkipBtn">Skip tip</button>
      <button type="button" class="reg-btn-primary" id="tipConfirmBtn">Add tip & continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="openItemModal">
  <div class="reg-modal">
    <h2>Custom item</h2>
    <div class="lede">For one-off items not in inventory.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Description</label>
      <input type="text" id="openItemName" placeholder="What is it?">
    </div>
    <div style="margin-bottom:6px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Price</label>
      <input type="text" id="openItemPrice" placeholder="0.00" inputmode="decimal">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="openItemModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="openItemAddBtn">Add to cart</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="customerModal">
  <div class="reg-modal">
    <h2>Attach customer</h2>
    <div style="margin-bottom:12px;position:relative">
      <input type="text" id="customerSearchInput" placeholder="Name, email, or phone" autocomplete="off">
      <div class="reg-cust-results" id="customerResults" style="display:none"></div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="customerModal">Cancel</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="confirmModal" style="z-index:1100">
  <div class="reg-modal" style="max-width:380px">
    <h2 id="confirmTitle">Are you sure?</h2>
    <div class="lede" id="confirmMessage"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="confirmCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="confirmOkBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="draftsModal">
  <div class="reg-modal" style="max-width:560px">
    <h2>Open drafts</h2>
    <div class="lede">Carts saved at this location.</div>
    <div class="reg-drafts-list" id="draftsList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="draftsModal" style="flex:1">Close</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="quoteModal">
  <div class="reg-modal">
    <h2>Save as quote</h2>
    <div class="lede">The customer can come back later to pick up where they left off.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Notes (optional)</label>
      <input type="text" id="quoteNotesInput" placeholder="Anything the customer should know">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="quoteModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="quoteSaveBtn">Save quote</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="refundModal">
  <div class="reg-modal" style="max-width:600px">
    <h2>Add refund items</h2>
    <div class="lede" id="refundModalLede">Select items to refund.</div>
    <div class="reg-refund-list" id="refundList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundAddBtn" disabled>Add to transaction</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="receiptModal">
  <div class="reg-modal reg-receipt">
    <h2>Sale complete</h2>
    <div class="num" id="receiptNum"></div>
    <div class="total" id="receiptTotal"></div>
    {{-- MARKER-PATCH-322 — print + email the receipt for this sale --}}
    <div class="reg-receipt-actions" style="display:flex;gap:8px;justify-content:center;margin:6px 0 2px">
      <button type="button" class="reg-btn-secondary" id="receiptPrintBtn">Print receipt</button>
      <button type="button" class="reg-btn-secondary" id="receiptEmailBtn">Email receipt</button>
    </div>
    <div id="receiptEmailPrompt" style="display:none;gap:6px;justify-content:center;align-items:center;margin:8px 0 2px">
      <input type="email" id="receiptEmailInput" placeholder="customer@email.com"
        style="background:var(--ia-input-bg,#0a0a0a);border:0.5px solid var(--ia-border,rgba(255,255,255,.13));border-radius:8px;color:var(--ia-text,#f0f0f0);font-size:13px;padding:8px 11px;font-family:inherit;width:210px">
      <button type="button" class="reg-btn-primary" id="receiptEmailSend">Send</button>
    </div>
    <div id="receiptEmailMsg" style="display:none;text-align:center;font-size:12px;margin-top:6px;color:var(--ia-text-dim)"></div>
    <div class="reg-modal-actions">
      {{-- MARKER-PATCH-232B — shown only when the register was opened with a return_to. --}}
      <a id="receiptBackTo" class="reg-btn-primary" style="display:none;text-decoration:none" href="#">Back</a>
      <button type="button" class="reg-btn-primary" id="receiptNewSale">New sale</button>
    </div>
    {{-- MARKER-PATCH-187 — auto-reset countdown --}}
    <div class="reg-receipt-auto" id="receiptAutoReset">Returning to a fresh register in <span id="receiptCountdown">45</span>s</div>
  </div>
</div>


@if(!empty($preAttachCustomer))
<script>
  // Patch 46: pre-attach customer from walk-in flow query param.
  // Runs after the register page's cart JS has initialized.
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof cart !== 'undefined' && cart) {
      cart.customer = @json($preAttachCustomer);
      if (typeof renderCart === 'function') renderCart();
      if (typeof queueDraftSave === 'function') queueDraftSave();
    }
  });
</script>
@endif

{{-- MARKER-PATCH-553 — item detail modal v2 (supersedes the 552 modal):
     gallery, brand header, permissioned cost/margin, badges, specs grid,
     stock table, action footer. --}}
<style>
  .reg-info-btn{flex:none;width:22px;height:22px;border-radius:50%;border:0.5px solid var(--ia-border);background:none;color:var(--ia-text-muted);font:italic 700 11px Georgia,serif;cursor:pointer;margin:0 10px;align-self:center}
  .reg-info-btn:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  #rim .rim-box{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:16px;width:min(680px,calc(100vw - 28px));max-height:88vh;overflow-y:auto}
  #rim .rim-head{display:flex;gap:18px;padding:22px 24px 18px;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-gal{flex:none;width:150px}
  #rim .rim-main{width:150px;height:150px;background:#fff;border-radius:12px;object-fit:contain;display:none}
  #rim .rim-main.ph{display:grid;place-items:center;color:#999;font-size:11px;background:var(--ia-surface-2,#222)}
  #rim .rim-thumbs{display:flex;gap:6px;margin-top:8px}
  #rim .rim-thumbs img{width:33px;height:33px;background:#fff;border-radius:7px;object-fit:contain;opacity:.55;cursor:pointer;border:1.5px solid transparent}
  #rim .rim-thumbs img.on{opacity:1;border-color:var(--ia-accent)}
  #rim .rim-brand{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-accent);font-weight:700}
  #rim h2{font-size:17px;line-height:1.35;margin:3px 0 4px;font-weight:700}
  #rim .rim-sub{font-size:12.5px;color:var(--ia-text-muted)}
  #rim .rim-price-row{display:flex;align-items:baseline;gap:14px;margin-top:12px;flex-wrap:wrap}
  #rim .rim-price{font:700 22px inherit;color:var(--ia-accent)}
  #rim .rim-cost{font-size:12px;color:var(--ia-text-muted)}
  #rim .rim-cost b{color:#8FD14F;font-weight:600}
  #rim .rim-badges{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
  #rim .rim-badge{font-size:10.5px;border:0.5px solid var(--ia-border);border-radius:99px;padding:2px 9px;color:var(--ia-text-muted)}
  #rim .rim-badge.ok{color:#8FD14F;border-color:#8FD14F}
  #rim .rim-body{padding:6px 24px 8px}
  #rim .rim-sec{padding:14px 0;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-sec:last-child{border-bottom:0}
  #rim .rim-sec h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:9px;font-weight:600}
  #rim .rim-attrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 22px;font-size:12.5px}
  #rim .rim-attrs .k{color:var(--ia-text-muted)}
  #rim table{width:100%;border-collapse:collapse;font-size:12.5px}
  #rim td{padding:5px 0;vertical-align:top}
  #rim td.k{color:var(--ia-text-muted);width:130px}
  #rim td.n{text-align:right;font-variant-numeric:tabular-nums}
  #rim .rim-foot{display:flex;gap:10px;padding:16px 24px 20px;border-top:0.5px solid var(--ia-border);position:sticky;bottom:0;background:var(--ia-surface)}
  #rim .rim-foot .grow{flex:1}
</style>
<div id="rim" style="display:none;position:fixed;inset:0;z-index:210;align-items:center;justify-content:center;background:rgba(0,0,0,.6)" onclick="if(event.target===this)this.style.display='none'">
  <div class="rim-box">
    <div class="rim-head">
      <div class="rim-gal">
        <img class="rim-main" id="rim-main" alt="">
        <div class="rim-main ph" id="rim-ph">no image</div>
        <div class="rim-thumbs" id="rim-thumbs"></div>
      </div>
      <div style="min-width:0">
        <div class="rim-brand" id="rim-brand"></div>
        <h2 id="rim-name"></h2>
        <div class="rim-sub" id="rim-sub"></div>
        <div class="rim-price-row">
          <span class="rim-price" id="rim-price"></span>
          <span class="rim-cost" id="rim-cost"></span>
        </div>
        <div class="rim-badges" id="rim-badges"></div>
      </div>
    </div>
    <div class="rim-body">
      <div class="rim-sec" id="rim-sec-desc" style="display:none"><h3>Description</h3><div id="rim-desc" style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.55"></div></div>
      <div class="rim-sec" id="rim-sec-attrs" style="display:none"><h3>Specs</h3><div class="rim-attrs" id="rim-attrs"></div></div>
      <div class="rim-sec"><h3>Stock &amp; identifiers</h3><table id="rim-table"></table></div>
    </div>
    <div class="rim-foot">
      <a class="ia-btn ia-btn--ghost" id="rim-edit" href="#" style="text-decoration:none">Edit item</a>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="document.getElementById('rim').style.display='none'">Close</button>
      <button type="button" class="ia-btn ia-btn--primary grow" id="rim-add">Add to sale</button>
    </div>
  </div>
</div>
<script>
// MARKER-PATCH-553
let rimItem = null;
async function openItemInfo(id) {
  const m = document.getElementById('rim');
  m.style.display = 'flex';
  rimItem = null;
  document.getElementById('rim-name').textContent = 'Loading…';
  ['rim-brand','rim-sub','rim-price','rim-cost','rim-desc'].forEach(x => document.getElementById(x).textContent = '');
  document.getElementById('rim-badges').innerHTML = '';
  document.getElementById('rim-attrs').innerHTML = '';
  document.getElementById('rim-table').innerHTML = '';
  document.getElementById('rim-thumbs').innerHTML = '';
  document.getElementById('rim-main').style.display = 'none';
  document.getElementById('rim-ph').style.display = 'grid';
  document.getElementById('rim-sec-desc').style.display = 'none';
  document.getElementById('rim-sec-attrs').style.display = 'none';
  try {
    const r = await fetch('/admin/register/item/' + encodeURIComponent(id) + '/info', { headers: { 'Accept': 'application/json' } });
    const d = await r.json();
    if (!d || !d.ok) throw new Error();
    rimItem = { type: 'product', source_id: id, name: d.name, price_cents: d.price_cents, is_taxable: d.taxable };

    document.getElementById('rim-brand').textContent = d.brand || '';
    document.getElementById('rim-name').textContent  = d.name || '';
    document.getElementById('rim-sub').textContent   = d.subtitle || '';
    document.getElementById('rim-price').textContent = fmt(d.price_cents);
    if (d.cost && d.cost.cost_cents) {
      document.getElementById('rim-cost').innerHTML = 'cost ' + fmt(d.cost.cost_cents)
        + (d.cost.margin_pct !== null ? ' · margin <b>' + d.cost.margin_pct + '%</b>' : '');
    }

    const imgs = d.images || [];
    if (imgs.length) {
      const main = document.getElementById('rim-main');
      main.src = imgs[0]; main.style.display = 'block';
      document.getElementById('rim-ph').style.display = 'none';
      if (imgs.length > 1) {
        document.getElementById('rim-thumbs').innerHTML = imgs.map((u, i) =>
          '<img src="' + u + '" class="' + (i === 0 ? 'on' : '') + '" onclick="rimSwap(this)">').join('');
      }
    }

    const here = (d.stock || []).reduce((a, s2) => a + (s2.count || 0), 0);
    const badges = [];
    badges.push('<span class="rim-badge ' + (here > 0 ? 'ok' : '') + '">' + here + ' in stock</span>');
    badges.push('<span class="rim-badge">' + (d.taxable ? 'taxable' : 'tax exempt') + '</span>');
    if (d.sold_30d > 0) badges.push('<span class="rim-badge">sold ' + (+d.sold_30d).toFixed(0) + ' in 30d</span>');
    document.getElementById('rim-badges').innerHTML = badges.join('');

    if (d.description) { document.getElementById('rim-desc').textContent = d.description; document.getElementById('rim-sec-desc').style.display = ''; }
    if (d.attrs && d.attrs.length) {
      document.getElementById('rim-attrs').innerHTML = d.attrs.map(a =>
        '<span class="k">' + escapeHtml(a.name) + '</span><span>' + escapeHtml(a.value) + '</span>').join('');
      document.getElementById('rim-sec-attrs').style.display = '';
    }

    const rows = [];
    (d.stock || []).forEach(s2 => rows.push(['<td class="k">' + escapeHtml(s2.location) + '</td>', '<td class="n">' + s2.count + '</td>']));
    if (d.sku)      rows.push(['<td class="k">SKU</td>', '<td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + escapeHtml(d.sku) + '</td>']);
    if (d.upc)      rows.push(['<td class="k">UPC</td>', '<td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + escapeHtml(d.upc) + '</td>']);
    if (d.category) rows.push(['<td class="k">Category</td>', '<td class="n">' + escapeHtml(d.category) + '</td>']);
    document.getElementById('rim-table').innerHTML = rows.map(r2 =>
      '<tr style="border-top:0.5px solid var(--ia-border)">' + r2.join('') + '</tr>').join('');

    document.getElementById('rim-edit').href = d.edit_url || '#';
    const add = document.getElementById('rim-add');
    add.textContent = 'Add to sale — ' + fmt(d.price_cents);
    add.onclick = () => {
      if (rimItem) addToCart(rimItem);
      document.getElementById('rim').style.display = 'none';
    };
  } catch (e) {
    document.getElementById('rim-name').textContent = 'Could not load item.';
  }
}
function rimSwap(el) {
  document.getElementById('rim-main').src = el.src;
  document.querySelectorAll('#rim-thumbs img').forEach(t => t.classList.remove('on'));
  el.classList.add('on');
}
</script>

@endsection

@push('scripts')
<script>
const ROUTES = {
  search:      @json(route('tenant.register.search')),
  storeSale:   @json(route('tenant.register.sales.store')),
  offlineCatalog: @json(route('tenant.register.offline_catalog')), // MARKER-OFFLINE-SYNC
  offlineSyncEnabled: {{ ($offlineSyncEnabled ?? false) ? 'true' : 'false' }}, // MARKER-OFFLINE-SYNC
  storeDraft:  @json(route('tenant.register.drafts.store')),
  listDrafts:  @json(route('tenant.register.drafts.index')),
  draftBase:   @json(url('/admin/register/drafts')),
  commitDraft: @json(url('/admin/register/drafts')),
  storeQuote:  @json(route('tenant.register.quotes.store')),
  quotesIndex: @json(route('tenant.register.quotes.index')),
  lookupSale:  @json(route('tenant.register.lookup-sale')),
  commitTxn:   @json(route('tenant.register.transactions.store')),
  // MARKER-PATCH-161
  customerBase: @json(url('/admin/customers')),
  // MARKER-PATCH-162
  multiLocationActive: {{ $multiLocationActive ? 'true' : 'false' }},
  // MARKER-PATCH-170 — Direct Payments
  directPaymentsEnabled: {{ (($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? 'true' : 'false' }}, {{-- MARKER-PATCH-618 --}}
  directPaymentsPk: @json((($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? (($tenant->settings['register_payments_mode'] ?? 'test') === 'live' ? ($tenant->settings['register_payments_live_pk'] ?? '') : ($tenant->settings['register_payments_test_pk'] ?? '')) : ''),
  paymentIntentCreate: @json(url('/admin/register/payment-intent')),
  paymentIntentConfirm: @json(url('/admin/register/payment-intent/confirm')),
  // MARKER-PATCH-170B
  paymentIntentAutoRefund: @json(url('/admin/register/payment-intent/auto-refund')),
  // MARKER-PATCH-172
  checkoutSessionCreate: @json(url('/admin/register/checkout-session')),
  checkoutSessionCheck:  @json(url('/admin/register/checkout-session/check')),
  saleShow:              @json(route('tenant.register.sales.show', ['id' => '__ID__'])), {{-- MARKER-PATCH-195 --}}
  saleReceipt:           @json(route('tenant.register.sales.receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  resendReceipt:         @json(route('tenant.sales.resend_receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  checkoutSessionCancel: @json(url('/admin/register/checkout-session/cancel')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;

// MARKER-REGISTER-RECON-DISPLAY — customer display mirroring.
// Debounced snapshots of the cart are pushed to the currently selected
// register; a paired iPad polls that register's snapshot and renders it.
const DisplayMirror = {
  enabled: {{ ($currentRegisterId ?? 0) > 0 ? 'true' : 'false' }},
  payUrl: null,
  timer: null,
  stateUrl: @json(route('tenant.register.display_state')),
  selectUrl: @json(route('tenant.register.select')),
};
function displaySnapshot() {
  const items = [];
  for (const i of cart.items) items.push({ name: i.name, qty: i.qty, line_cents: Math.round(i.price_cents * i.qty) });
  for (const r of cart.refund_lines) items.push({ name: r.name, qty: r.qty, line_cents: Math.round(r.price_cents * r.qty), refund: true });
  const sub = calcSubtotal() - calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const total = (calcSubtotal() - cart.discountCents + tax + surch + cart.tipCents) - (calcRefundSubtotal());
  return {
    state: DisplayMirror.payUrl ? 'pay' : (items.length ? 'cart' : 'idle'),
    items,
    subtotal_cents: sub,
    discount_cents: cart.discountCents,
    tax_cents: tax,
    tax_label: CFG.taxLabel || null,
    surcharge_cents: surch,
    tip_cents: cart.tipCents,
    total_cents: Math.max(0, Math.round(total)),
    pay_url: DisplayMirror.payUrl,
  };
}
function queueDisplayMirror(immediate = false) {
  if (!DisplayMirror.enabled) return;
  clearTimeout(DisplayMirror.timer);
  DisplayMirror.timer = setTimeout(() => {
    fetch(DisplayMirror.stateUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(displaySnapshot()),
    }).catch(() => {});
  }, immediate ? 0 : 400);
}

// MARKER-OFFLINE-SYNC stage 3 — register-specific offline behavior.
// Core (outbox, snapshot, replay, SW registration, status pill) lives in the
// global /js/offline-sync.js module loaded by the layout on every admin page;
// this block only handles what's unique to the register: queueing a commit,
// snapshot search, and disabling network-only tenders.
function osToggleTenders(online) {
  document.querySelectorAll('.reg-tender-btn').forEach(b => {
    const t = b.dataset.tender || b.dataset.refundTender;
    if (t === 'card' || t === 'payment_link') {
      const block = !online && window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused;
      b.disabled = block;
      b.style.opacity = block ? '.35' : '';
      b.title = block ? 'Unavailable offline' : '';
    }
  });
}
document.addEventListener('intake-offline-status', e => osToggleTenders(e.detail.online));
if (window.IntakeOffline) osToggleTenders(navigator.onLine);

function osBuildSalePayload(){
  return {
    client_uuid: IntakeOffline.uuid(),
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    discount_cents: cart.discountCents,
    payment_method: cart.payment_method,
    payment_reference: cart.payment_reference,
    items: cart.items.map(serializeLine),
    skip_receipt: cart.skipReceipt ? 1 : 0,
  };
}
async function osTryQueueCommit(){
  const io = window.IntakeOffline;
  if (!io || !io.enabled || io.paused || !io.db) return false;
  if (cart.refund_lines.length > 0) return false;
  if (cart.stripe_payment_intent_id) return false;
  if (cart.payment_method === 'card' || cart.payment_method === 'payment_link') return false;
  if (!cart.items.length) return false;
  await io.queueSale(osBuildSalePayload());
  cart.items = []; cart.refund_lines = []; cart.refund_meta = null;
  cart.customer = null; cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  cart.draft_id = null; cart.skipReceipt = false;
  renderCart();
  showError('Saved offline — this sale will sync automatically when the connection returns.');
  return true;
}
function osSearchSnapshot(q){
  return (window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused)
    ? IntakeOffline.snapshotSearch(q) : null;
}

const registerPickerEl = document.getElementById('registerPicker');
if (registerPickerEl) {
  registerPickerEl.addEventListener('change', async () => {
    const id = parseInt(registerPickerEl.value, 10) || 0;
    try {
      await fetch(DisplayMirror.selectUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ register_id: id }),
      });
      DisplayMirror.enabled = id > 0;
      queueDisplayMirror(true);
    } catch (e) {}
  });
}
const CFG = {
  taxRate:        {{ $taxRate ?? 0 }},
  taxLabel:       @json($taxLabel ?? ''),
  tipsEnabled:    {{ $tipsConfig['enabled'] ? 'true' : 'false' }},
  tipMethod:      @json($tipsConfig['method'] ?? null),
  tipOptions:     @json($tipsConfig['options'] ?? []),
  tipAllowCustom: {{ $tipsConfig['allow_custom'] ? 'true' : 'false' }},
  surchargeOn:    {{ $surchargeConfig['enabled'] ? 'true' : 'false' }},
  surchargePct:   {{ $surchargeConfig['percent'] ?? 0 }},
  surchargeLabel: @json($surchargeConfig['label'] ?? 'Surcharge'),
};

// Reusable confirm dialog. Returns a promise that resolves true/false.
// Usage: const ok = await confirmDialog('Replace cart?', 'Replace');
function confirmDialog(message, confirmLabel = 'Confirm', title = 'Are you sure?') {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    const okBtn = document.getElementById('confirmOkBtn');
    const cancelBtn = document.getElementById('confirmCancelBtn');
    okBtn.textContent = confirmLabel;
    const cleanup = (result) => {
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      closeModal('confirmModal');
      resolve(result);
    };
    const onOk = () => cleanup(true);
    const onCancel = () => cleanup(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    openModal('confirmModal');
  });
}

const cart = {
  draft_id: null,
  customer: null,
  items: [],            // new-sale lines
  refund_lines: [],     // refund lines, each: {key, original_sale_id, original_item_id, name, qty, price_cents, type}
  refund_meta: null,    // {original_sale_id, original_sale_number, refund_method} — set when first refund line added
  tipCents: 0, discountCents: 0,
  payment_method: null, payment_reference: null,
  payments: [], // MARKER-SPLIT-TENDER
  tax_locked: false,    // when true, calcTax sums per-line tax_cents instead of computing from rate
  skipReceipt: false,   // MARKER-PATCH-161 — cashier opted out of receipt for this sale
};
const fmt = (cents) => '$' + (cents / 100).toFixed(2);
const fmtNeg = (cents) => '-$' + (cents / 100).toFixed(2);
let lineKey = 0;

// --- Draft auto-save infrastructure ---
// Cart changes debounce a save to /register/drafts. First save creates the
// draft and stores its id on cart.draft_id. Subsequent saves include the id
// to update in place. Mark Paid awaits any pending save, then commits.
const DRAFT_DEBOUNCE_MS = 1500;
let draftSaveTimer = null;
let draftSaveInFlight = null; // Promise of currently-firing save, or null.

function buildDraftPayload() {
  return {
    id: cart.draft_id,
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      // Round-trip per-line tax for tax_locked sales so recalc preserves it.
      if (cart.tax_locked) {
        out.tax_cents = i.tax_cents || 0;
        if (i.tax_rate_snapshot != null) out.tax_rate_snapshot = i.tax_rate_snapshot;
      }
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };
}

async function fireDraftSave() {
  // If a save is already in flight, wait for it and re-queue this one.
  // Last-write-wins: the next save will include the latest cart state.
  if (draftSaveInFlight) {
    await draftSaveInFlight;
  }
  const payload = buildDraftPayload();
  draftSaveInFlight = (async () => {
    try {
      const res = await fetch(ROUTES.storeDraft, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.ok && data.draft_id) {
        cart.draft_id = data.draft_id;
        setSaveStatus('saved');
      }
    } catch (e) {
      // Silent failure on auto-save. Cart still works locally; commit will
      // fall back to the storeSale path if draft_id is still null.
      console.warn('[draft] save failed', e);
      setSaveStatus('idle');
    } finally {
      draftSaveInFlight = null;
    }
  })();
  return draftSaveInFlight;
}

function queueDraftSave() {
  // Empty cart with no existing draft — nothing to save.
  if (!cart.items.length && !cart.draft_id) return;
  clearTimeout(draftSaveTimer);
  draftSaveTimer = setTimeout(fireDraftSave, DRAFT_DEBOUNCE_MS);
  setSaveStatus('pending');
}

let saveStatusTimer = null;
function setSaveStatus(state) {
  const el = document.getElementById('saveStatus');
  if (!el) return;
  clearTimeout(saveStatusTimer);
  if (state === 'pending' || state === 'saving') {
    el.textContent = 'Saving…';
    el.classList.add('visible');
  } else if (state === 'saved') {
    el.textContent = 'Saved';
    el.classList.add('visible');
    saveStatusTimer = setTimeout(() => el.classList.remove('visible'), 1500);
  } else {
    el.classList.remove('visible');
  }
}

async function flushDraftSave() {
  // Cancel any pending debounce, fire immediately, await any in-flight save.
  clearTimeout(draftSaveTimer);
  draftSaveTimer = null;
  if (cart.items.length || cart.draft_id) {
    await fireDraftSave();
  }
  if (draftSaveInFlight) await draftSaveInFlight;
}

const searchInput = document.getElementById('searchInput');
const resultsArea = document.getElementById('resultsArea');
let searchType = 'all';
let searchTimer = null;

document.querySelectorAll('.reg-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    searchType = tab.dataset.type;
    runSearch();
  });
});
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(runSearch, 250);
});

// Detect sale-number pattern: S-YYYYMMDD-NNN (case-insensitive, optional spaces around dashes)
function looksLikeSaleNumber(q) {
  return /^s[\s-]*\d{8}[\s-]*\d{1,4}$/i.test(q.trim());
}
function normalizeSaleNumber(q) {
  return q.trim().toUpperCase().replace(/\s+/g, '').replace(/^S(\d)/, 'S-$1').replace(/(\d{8})(\d)/, '$1-$2');
}

async function runSearch() {
  const q = searchInput.value.trim();
  if (q.length < 2) {
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    return;
  }

  // Sale-number lookup runs in parallel with regular search.
  let refundResult = null;
  if (looksLikeSaleNumber(q)) {
    try {
      const lookupUrl = new URL(ROUTES.lookupSale, window.location.origin);
      lookupUrl.searchParams.set('sale_number', normalizeSaleNumber(q));
      const r = await fetch(lookupUrl, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (d.ok) refundResult = d.sale;
    } catch (e) { /* silent — fall through to regular search */ }
  }

  try {
    const url = new URL(ROUTES.search, window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('type', searchType);
    const res = await fetch(url, {headers: {'Accept': 'application/json'}});
    const data = await res.json();
    renderResults(data, refundResult);
  } catch (e) {
    // MARKER-OFFLINE-SYNC — offline: search the cached catalog snapshot.
    const snap = osSearchSnapshot(q);
    if (snap && (snap.products.length || snap.services.length)) {
      renderResults(snap, null);
      resultsArea.insertAdjacentHTML('afterbegin',
        '<div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#F5C56B;margin-bottom:8px">Offline — cached catalog snapshot</div>');
    } else {
      resultsArea.innerHTML = '<div class="reg-empty">' + (!navigator.onLine ? 'Offline — no cached matches.' : 'Search failed.') + '</div>';
    }
  }
}

// Keyboard nav state
let highlighted = 0;
let visibleResults = [];

function renderResults(data, refundResult) {
  let html = '';
  visibleResults = [];

  // If a refund-eligible sale was matched, render it first as a distinctive card.
  if (refundResult) {
    html += '<div class="reg-refund-result" data-refund-sale="' + refundResult.id + '">';
    html +=   '<div class="label">Refund from sale</div>';
    html +=   '<div class="name">#' + escapeHtml(refundResult.sale_number) + '</div>';
    html +=   '<div class="meta">' + (refundResult.customer ? escapeHtml(refundResult.customer) + ' · ' : '');
    html +=     fmt(refundResult.total_cents) + ' · ' + (refundResult.items.length) + ' items</div>';
    html += '</div>';
  }

  if (data.products && data.products.length) {
    html += '<div class="reg-results-section"><h3>Products</h3>';
    data.products.forEach(p => {
      visibleResults.push({type:'product',source_id:p.id,name:p.name,price_cents:p.price_cents,is_taxable:p.is_taxable,current_location_stock:p.current_location_stock,current_location_name:p.current_location_name});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(p.name)}</div><div class="meta">${escapeHtml(p.subtitle || p.sku || '')}</div></div>
        <button type="button" class="reg-info-btn" data-item-id="${p.id}" title="Item details" aria-label="Item details">i</button>
        <div class="price">${fmt(p.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (data.services && data.services.length) {
    html += '<div class="reg-results-section mouse-defer"><h3>Services</h3>';
    data.services.forEach(s => {
      visibleResults.push({type:'service',source_id:s.id,name:s.name,price_cents:s.price_cents,is_taxable:true});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(s.name)}</div><div class="meta">${s.duration_minutes || 0} min</div></div>
        <div class="price">${fmt(s.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (!html) html = '<div class="reg-empty">No matches.</div>';
  resultsArea.innerHTML = html;

  // Show/hide keyboard hint based on whether results exist
  const hint = document.getElementById('regHint');
  hint.style.display = visibleResults.length ? '' : 'none';

  // Reset highlight to first row
  if (highlighted >= visibleResults.length) highlighted = 0;
  applyHighlight();

  // MARKER-PATCH-552 — info buttons open the item modal; stop the row's add-to-cart
  resultsArea.querySelectorAll('.reg-info-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openItemInfo(btn.dataset.itemId);
    });
  });

  // Click handler — add the row's item, then clear search and refocus (same as Enter)
  resultsArea.querySelectorAll('[data-i]').forEach(row => {
    row.addEventListener('click', () => {
      const i = parseInt(row.dataset.i, 10);
      addToCart(visibleResults[i]);
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    });
  });

  // Wire refund-result click → open picker modal.
  const refundEl = resultsArea.querySelector('[data-refund-sale]');
  if (refundEl) {
    // Stash the refund result on the element via dataset for the click handler.
    refundEl.addEventListener('click', () => {
      // Re-fetch the sale to get fresh refundable quantities (in case anything changed).
      const saleId = refundEl.dataset.refundSale;
      openRefundPicker(saleId);
    });
  }

  // Wire mouse-active class to the search panel's results sections
  resultsArea.querySelectorAll('.reg-results-section').forEach(section => {
    section.addEventListener('mouseenter', () => section.classList.add('mouse-active'));
    section.addEventListener('mouseleave', () => section.classList.remove('mouse-active'));
  });
}

function applyHighlight() {
  resultsArea.querySelectorAll('.reg-row').forEach((row, i) => {
    if (parseInt(row.dataset.i, 10) === highlighted) {
      row.classList.add('highlighted');
    } else {
      row.classList.remove('highlighted');
    }
  });
}

// Keyboard navigation on the search input
searchInput.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (highlighted < visibleResults.length - 1) { highlighted++; applyHighlight(); }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (highlighted > 0) { highlighted--; applyHighlight(); }
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (visibleResults[highlighted]) {
      addToCart(visibleResults[highlighted]);
      // Clear search and refocus for next item
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    }
  } else if (e.key === 'Escape') {
    searchInput.value = '';
    visibleResults = [];
    highlighted = 0;
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    document.getElementById('regHint').style.display = 'none';
  }
});

function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function addToCart(item) {
  // patch-96 cart-meta + patch-100a oversell-actions — store stock data
  // and any action-state (transfer / SO) on the cart line so it persists
  // through re-renders and draft saves.
  cart.items.push({
    key: ++lineKey, type: item.type, source_id: item.source_id,
    name: item.name, price_cents: item.price_cents, qty: 1,
    is_taxable: item.is_taxable !== false,
    current_location_stock: (typeof item.current_location_stock === 'number')
      ? item.current_location_stock : null,
    current_location_name: item.current_location_name || null,
    transfer_request_id: null,
    transfer_request_from: null,
    special_order_id: null,
    so_number: null,
  });
  renderCart();
  queueDraftSave();
}

// patch-100a oversell-actions — handlers for the two action buttons.
// Both find the cart line, POST to the endpoint, then mutate the line's
// state fields so the next renderCart() swaps button for pill.

function requestTransferForLine(key) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.transfer_request_id) return;
  fetch('{{ route('tenant.register.oversell.transfer-request') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.transfer_request_id = data.transfer_request_id;
      line.transfer_request_from = data.from_location_name || null;
      renderCart();
      queueDraftSave();
    } else {
      alert('Transfer request failed: ' + (data.error || 'unknown error'));
    }
  })
  .catch(err => alert('Transfer request error: ' + err.message));
}

function addToOrderForLine(key) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.special_order_id) return;
  fetch('{{ route('tenant.register.oversell.special-order') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
      customer_id: cart.customer_id || null,
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.special_order_id = data.special_order_id;
      line.so_number = data.so_number;
      renderCart();
      queueDraftSave();
    } else {
      alert('Add to order failed: ' + (data.error || 'unknown error'));
    }
  })
  .catch(err => alert('Add to order error: ' + err.message));
}

function removeLine(key) {
  cart.items = cart.items.filter(i => i.key !== key);
  renderCart();
  queueDraftSave();
}
function updateQty(key, qty) {
  const n = parseFloat(qty);
  if (isNaN(n) || n <= 0) { removeLine(key); return; }
  const line = cart.items.find(i => i.key === key);
  if (line) line.qty = n;
  renderCart();
  queueDraftSave();
}

function renderCart() {
  const lines = document.getElementById('cartLines');
  const totalCount = cart.items.length + cart.refund_lines.length;
  if (totalCount === 0) {
    lines.innerHTML = '<div class="reg-empty">Cart is empty.</div>';
    document.getElementById('payBtn').disabled = true;
    document.getElementById('quoteBtn').disabled = true;
  } else {
    let html = '';

    // Refund section — render first (visually on top) when present.
    if (cart.refund_lines.length > 0) {
      html += '<div class="reg-cart-section-label refund">Returning to customer · sale #' +
        escapeHtml(cart.refund_meta?.original_sale_number ?? '') + '</div>';
      html += cart.refund_lines.map(r => `
        <div class="reg-line refund-line">
          <div>
            <div class="name">${escapeHtml(r.name)}</div>
            <div class="meta">refund · ${r.qty} × ${fmt(r.price_cents)}</div>
            ${r.type === 'product' ? `
            <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px">
              <span style="opacity:.65">Goes to</span>
              <select class="reg-dispo" data-dispo="${r.key}"
                      style="background:transparent;border:1px solid var(--ia-border);border-radius:7px;color:inherit;font-family:inherit;font-size:11.5px;padding:3px 6px">
                <option value="restock"${(r.disposition||'restock')==='restock'?' selected':''}>Restock — sellable</option>
                <option value="open_box"${r.disposition==='open_box'?' selected':''}>Open box — sellable</option>
                <option value="damaged"${r.disposition==='damaged'?' selected':''}>Damaged</option>
                <option value="defective"${r.disposition==='defective'?' selected':''}>Defective</option>
                <option value="warranty_hold"${r.disposition==='warranty_hold'?' selected':''}>Warranty hold</option>
                <option value="return_vendor"${r.disposition==='return_vendor'?' selected':''}>Return to vendor</option>
                <option value="scrap"${r.disposition==='scrap'?' selected':''}>Scrap</option>
                <option value="customer_keeps"${r.disposition==='customer_keeps'?' selected':''}>Customer keeps item</option>
              </select>
              ${['restock','open_box'].includes(r.disposition||'restock')
                ? '<span style="color:var(--ia-accent)">back to stock</span>'
                : (r.disposition === 'customer_keeps' ? '<span style="opacity:.6">no stock change</span>' : '<span style="color:#F5C56B">off the shelf</span>')}
            </div>` : ''}
          </div>
          <div></div>
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">-${fmt(Math.round(r.price_cents * r.qty))}</span>
            <button type="button" class="remove" data-remove-refund="${r.key}">×</button>
          </div>
        </div>
      `).join('');
    }

    // New-sale section
    if (cart.items.length > 0) {
      if (cart.refund_lines.length > 0) {
        html += '<div class="reg-cart-section-label">Adding to cart</div>';
      }
      html += cart.items.map(i => {
        // patch-96 oversell-badge + patch-100a oversell-actions — show badge
        // and an action row below the line when the qty exceeds local stock.
        let badge = '';
        let actionRow = '';
        const isOversold = typeof i.current_location_stock === 'number'
                           && i.qty > i.current_location_stock;
        if (isOversold) {
          const overBy = i.qty - i.current_location_stock;
          const locLabel = i.current_location_name ? ' at ' + escapeHtml(i.current_location_name) : '';
          badge = `<span class="reg-oversell-badge" title="Stock will go to ${i.current_location_stock - i.qty}${locLabel}">⚠ short ${overBy}${locLabel}</span>`;

          // Action row: each button is either active (button) or already-fired (pill).
          // MARKER-PATCH-162 — transfer button only renders when the tenant
          // has 2+ active locations to move stock between. Single-location
          // tenants still see the pill if a transfer was previously created
          // (orphan rows pre-patch), but can't create new ones.
          let transferBtn = '';
          if (i.transfer_request_id) {
            const fromLabel = i.transfer_request_from ? ' from ' + escapeHtml(i.transfer_request_from) : '';
            transferBtn = `<span class="reg-oversell-pill">✓ Transfer requested${fromLabel}</span>`;
          } else if (ROUTES.multiLocationActive && i.type === 'product' && i.source_id) {
            transferBtn = `<button type="button" class="reg-oversell-btn" data-action="transfer" data-key="${i.key}">Request transfer</button>`;
          }

          let soBtn = '';
          if (i.special_order_id) {
            soBtn = `<span class="reg-oversell-pill">✓ ${escapeHtml(i.so_number || 'SO created')}</span>`;
          } else if (i.type === 'product' && i.source_id) {
            soBtn = `<button type="button" class="reg-oversell-btn" data-action="so" data-key="${i.key}">Add to order</button>`;
          }

          if (transferBtn || soBtn) {
            actionRow = `<div class="reg-oversell-actions">${transferBtn}${soBtn}</div>`;
          }
        }

        return `
        <div class="reg-line">
          <div>
            <div class="name">${escapeHtml(i.name)} ${badge}</div>
            <div class="meta">${fmt(i.price_cents)} · ${i.type}</div>
            ${actionRow}
          </div>
          <input type="text" class="qty" value="${i.qty}" data-key="${i.key}" inputmode="decimal">
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">${fmt(Math.round(i.price_cents * i.qty))}</span>
            <button type="button" class="remove" data-remove="${i.key}">×</button>
          </div>
        </div>
      `;
      }).join('');
    }

    lines.innerHTML = html;
    document.getElementById('payBtn').disabled = false;
    document.getElementById('quoteBtn').disabled = false;
  }
  lines.querySelectorAll('[data-key]').forEach(input => {
    input.addEventListener('change', () => updateQty(parseInt(input.dataset.key, 10), input.value));
  });
  lines.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => removeLine(parseInt(btn.dataset.remove, 10)));
  });
  // patch-100a oversell-actions — wire the action buttons
  lines.querySelectorAll('[data-action="transfer"]').forEach(btn => {
    btn.addEventListener('click', () => requestTransferForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-action="so"]').forEach(btn => {
    btn.addEventListener('click', () => addToOrderForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-remove-refund]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = parseInt(btn.dataset.removeRefund, 10);
      cart.refund_lines = cart.refund_lines.filter(r => r.key !== key);
      if (cart.refund_lines.length === 0) cart.refund_meta = null;
      renderCart();
    });
  });
  // MARKER-REFUND-QTY — where the returned goods go, per line.
  lines.querySelectorAll('[data-dispo]').forEach(sel => {
    sel.addEventListener('change', () => {
      const key = parseInt(sel.dataset.dispo, 10);
      const line = cart.refund_lines.find(r => r.key === key);
      if (line) { line.disposition = sel.value; renderCart(); }
    });
  });

  const slot = document.getElementById('customerSlot');
  if (cart.customer) {
    const c = cart.customer;
    const profileUrl = ROUTES.customerBase + '/' + encodeURIComponent(c.id);
    const emailRow = c.email
      ? `<a href="mailto:${escapeHtml(c.email)}">${escapeHtml(c.email)}</a>`
      : '';
    const phoneRow = c.phone
      ? `<a href="tel:${escapeHtml(c.phone)}">${escapeHtml(c.phone)}</a>`
      : '';
    const metaInner = (emailRow || phoneRow)
      ? `<div class="meta">${emailRow}${phoneRow}</div>`
      : '';
    // MARKER-PATCH-161 — receipt indicator
    const hasEmail = !!c.email;
    const skipChecked = cart.skipReceipt ? 'checked' : '';
    const receiptRow = hasEmail
      ? `<div class="reg-cust-receipt">
           <span class="reg-cust-receipt-status">
             <span class="reg-cust-receipt-dot"></span>
             Receipt will email to <b>${escapeHtml(c.email)}</b>
           </span>
           <label class="reg-cust-receipt-skip">
             <input type="checkbox" id="skipReceiptChk" ${skipChecked}>
             Skip receipt
           </label>
         </div>`
      : `<div class="reg-cust-receipt reg-cust-receipt--none">
           <span class="reg-cust-receipt-status">No email on file — no receipt will send</span>
         </div>`;

    slot.innerHTML = `
      <div class="reg-cust">
        <div class="head">
          <span class="name">${escapeHtml(c.name || '(no name)')}</span>
        </div>
        ${metaInner}
        ${receiptRow}
        <div class="actions">
          <a class="profile-link" href="${profileUrl}" target="_blank" rel="noopener">View profile →</a>
          <span class="clear" id="clearCust">Remove</span>
        </div>
      </div>`;
    var skipChk = document.getElementById('skipReceiptChk');
    if (skipChk) {
      skipChk.addEventListener('change', function(){
        cart.skipReceipt = !!skipChk.checked;
      });
    }
    document.getElementById('clearCust').addEventListener('click', () => {
      cart.customer = null;
      cart.skipReceipt = false; // MARKER-PATCH-161
      renderCart();
      queueDraftSave();
    });
    // Customer is now attached — clear any prior warning.
    if (customerWarningActive) applyCustomerWarning(false);
  } else {
    slot.innerHTML = `<button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>`;
    document.getElementById('attachCustBtn').addEventListener('click', openCustomerModal);
    // Re-apply warning class if a prior quote attempt set it.
    if (customerWarningActive) applyCustomerWarning(true);
  }
  renderTotals();
}

function calcSubtotal() { return cart.items.reduce((sum, i) => sum + Math.round(i.price_cents * i.qty), 0); }
function calcRefundSubtotal() {
  return cart.refund_lines.reduce((sum, r) => sum + Math.round(r.price_cents * r.qty), 0);
}
// Refund-line tax: snapshot from the original sale, summed across refund lines.
// Always honor the snapshot — refunds preserve historical tax even if rate changed.
function calcRefundTax() {
  return cart.refund_lines.reduce((s, r) => s + (r.tax_cents || 0), 0);
}
function calcTax() {
  // tax_locked: per-line tax was set externally (e.g. by the appointment bridge).
  if (cart.tax_locked) {
    return cart.items.reduce((s, i) => s + (i.tax_cents || 0), 0);
  }
  if (!CFG.taxRate) return 0;
  let taxable = 0;
  cart.items.forEach(i => { if (i.is_taxable) taxable += Math.round(i.price_cents * i.qty); });
  return Math.round(taxable * (CFG.taxRate / 100));
}
function calcSurcharge() {
  if (!CFG.surchargeOn) return 0;
  if (cart.payment_method !== 'card') return 0;
  return Math.round(calcSubtotal() * (CFG.surchargePct / 100));
}

function renderTotals() {
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const refundTax = calcRefundTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;

  // Display values reflect the NET cart (new lines minus refund lines).
  // Total = (subtotal - discount + tax + surcharge + tip) - (refund subtotal + refund tax).
  const netSub   = sub - refundSub;
  const netTax   = tax - refundTax;
  const total    = (sub - disc + tax + surch + tip) - (refundSub + refundTax);

  document.getElementById('subVal').textContent = fmt(netSub);
  document.getElementById('taxVal').textContent = fmt(netTax);
  document.getElementById('totalVal').textContent = fmt(total);

  if (disc > 0) { document.getElementById('discountRow').style.display = ''; document.getElementById('discVal').textContent = fmtNeg(disc); }
  else { document.getElementById('discountRow').style.display = 'none'; }
  if (surch > 0) { document.getElementById('surchargeRow').style.display = ''; document.getElementById('surchLabel').textContent = CFG.surchargeLabel; document.getElementById('surchVal').textContent = fmt(surch); }
  else { document.getElementById('surchargeRow').style.display = 'none'; }
  if (tip > 0) { document.getElementById('tipRow').style.display = ''; document.getElementById('tipVal').textContent = fmt(tip); }
  else { document.getElementById('tipRow').style.display = 'none'; }
  queueDisplayMirror(); // MARKER-REGISTER-RECON-DISPLAY
}

document.getElementById('addOpenItemBtn').addEventListener('click', () => {
  document.getElementById('openItemName').value = '';
  document.getElementById('openItemPrice').value = '';
  openModal('openItemModal');
});
document.getElementById('openItemAddBtn').addEventListener('click', () => {
  const name = document.getElementById('openItemName').value.trim();
  const priceStr = document.getElementById('openItemPrice').value.trim();
  const priceFloat = parseFloat(priceStr);
  if (!name || isNaN(priceFloat) || priceFloat < 0) return;
  const cents = Math.round(priceFloat * 100);
  addToCart({type:'open_item', source_id:null, name, price_cents:cents, is_taxable:true});
  closeModal('openItemModal');
});

function openCustomerModal() {
  document.getElementById('customerSearchInput').value = '';
  document.getElementById('customerResults').style.display = 'none';
  openModal('customerModal');
  setTimeout(() => document.getElementById('customerSearchInput').focus(), 50);
}
let custTimer = null;
document.getElementById('customerSearchInput').addEventListener('input', () => {
  clearTimeout(custTimer);
  custTimer = setTimeout(searchCustomers, 250);
});
async function searchCustomers() {
  const q = document.getElementById('customerSearchInput').value.trim();
  const box = document.getElementById('customerResults');
  if (q.length < 2) { box.style.display = 'none'; return; }
  const url = new URL(ROUTES.search, window.location.origin);
  url.searchParams.set('q', q);
  url.searchParams.set('type', 'customer');
  try {
    const res = await fetch(url, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.customers || !data.customers.length) {
      box.innerHTML = '<div class="row" style="color:var(--ia-text-dim)">No matches.</div>';
      box.style.display = '';
      return;
    }
    box.innerHTML = data.customers.map(c => `
      <div class="row" data-cust='${JSON.stringify(c)}'>
        <div style="font-weight:500">${escapeHtml(c.name || '(no name)')}</div>
        <div style="font-size:11px;color:var(--ia-text-dim)">${escapeHtml(c.email || c.phone || '')}</div>
      </div>
    `).join('');
    box.querySelectorAll('[data-cust]').forEach(row => {
      row.addEventListener('click', () => {
        cart.customer = JSON.parse(row.dataset.cust);
        closeModal('customerModal');
        renderCart();
        queueDraftSave();
      });
    });
    box.style.display = '';
  } catch (e) {
    box.innerHTML = '<div class="row" style="color:#F09595">Search failed.</div>';
    box.style.display = '';
  }
}

// --- Save as Quote flow ---
let customerWarningActive = false;

function applyCustomerWarning(on) {
  customerWarningActive = on;
  const slot = document.getElementById('customerSlot');
  const cust = slot.querySelector('.reg-cust');
  const attach = slot.querySelector('.reg-attach');
  if (on) {
    if (cust) cust.classList.add('warning');
    if (attach) attach.classList.add('warning');
  } else {
    if (cust) cust.classList.remove('warning');
    if (attach) attach.classList.remove('warning');
  }
}

document.getElementById('quoteBtn').addEventListener('click', async () => {
  if (cart.refund_lines.length > 0) {
    showError('Quotes can\'t include refund items. Remove the refund lines or commit the transaction.');
    return;
  }
  if (!cart.customer) {
    applyCustomerWarning(true);
    const ok = await confirmDialog(
      'Quotes need a customer attached so you can find and follow up later.',
      'Attach customer',
      'Customer required'
    );
    if (ok) openCustomerModal();
    return;
  }
  // Customer is attached — clear any prior warning state and open quote modal.
  applyCustomerWarning(false);
  document.getElementById('quoteNotesInput').value = '';
  openModal('quoteModal');
  setTimeout(() => document.getElementById('quoteNotesInput').focus(), 50);
});

document.getElementById('quoteSaveBtn').addEventListener('click', async () => {
  const btn = document.getElementById('quoteSaveBtn');
  btn.disabled = true;

  // Make sure any pending draft save lands first — same flush pattern as commit.
  await flushDraftSave();

  const payload = {
    id: cart.draft_id,
    customer_id: cart.customer.id,
    notes: document.getElementById('quoteNotesInput').value.trim() || null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };

  try {
    const res = await fetch(ROUTES.storeQuote, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      showError(data.error || 'Could not save the quote.');
      closeModal('quoteModal');
      return;
    }
    // Success — clear the cart so register is ready for the next sale.
    closeModal('quoteModal');
    cart.draft_id = null;
    cart.customer = null;
    cart.items = [];
    cart.tipCents = 0;
    cart.discountCents = 0;
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
    cart.payment_reference = null;
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error saving quote.');
    closeModal('quoteModal');
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('payBtn').addEventListener('click', () => {
  // MARKER-PATCH-170C — pre-flight validation FIRST. If the cart can't
  // be committed (e.g. service line without customer), block the tender
  // modal entirely and show a focused dialog explaining what to fix.
  const blocker = preflightCheck();
  if (blocker) {
    openPreflightModal(blocker);
    return;
  }

  // Net total decides which path we take.
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;
  const net = (sub - disc + tax + surch + tip) - refundSub;

  if (net === 0 && cart.refund_lines.length > 0) {
    // Even exchange — skip tender, commit immediately.
    // No money changes hands, but the payload still requires a payment method
    // for the validator. 'even_exchange' is a sentinel that the controller treats
    // the same as 'mark_paid' (no actual tender).
    cart.payment_method = 'even_exchange';
    commitTransaction({ even_exchange: true });
    return;
  }

  if (net < 0) {
    // Refund-direction transaction.
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
    document.getElementById('refundTenderConfirmBtn').disabled = true;
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('refundTenderLede').textContent =
      'Customer is owed ' + fmt(Math.abs(net)) + '. How is the refund being given?';
    openModal('refundTenderModal');
    return;
  }

  // Standard sale-direction tender flow (net > 0).
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
  cart.payment_reference = null;
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderManualRow').style.display = 'none'; // MARKER-PATCH-630
  document.getElementById('tenderRefInput').value = '';
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  openModal('tenderModal');
});

// Refund-tender modal handlers
document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.refundTender;
    document.getElementById('refundTenderConfirmBtn').disabled = false;
  });
});

document.getElementById('refundTenderConfirmBtn').addEventListener('click', () => {
  closeModal('refundTenderModal');
  // Refund-direction commits skip the tip step entirely.
  commitTransaction({});
});

// MARKER-SPLIT-TENDER — split-tender engine. Zero recorded payments = the
// classic single-tender flow, unchanged. Split only activates via "Add
// payment". Stripe card / payment-link / mark_paid stay full-amount-only
// until stage 2 and grey out mid-split.
const SplitCard = { pendingAmountCents: null }; // MARKER-SPLIT-ANYORDER
function splitDueCents() {
  const t = computeTotalsForCommit();
  return Math.max(0, t.total_cents + (cart.tipCents || 0) - (calcRefundSubtotal() + calcRefundTax()));
}
function splitPaidCents() { return cart.payments.reduce((s, p) => s + p.amount_cents, 0); }
function splitRemaining() { return Math.max(0, splitDueCents() - splitPaidCents()); }
function renderSplit() {
  const list = document.getElementById('splitPayList');
  const remRow = document.getElementById('splitRemainRow');
  if (!list || !remRow) return;
  const active = cart.payments.length > 0;
  remRow.style.display = active ? '' : 'none';
  list.innerHTML = '';
  cart.payments.forEach((p, i) => {
    const row = document.createElement('div');
    row.className = 'reg-split-row';
    // MARKER-SPLIT-ANYORDER — charged card rows are locked: removing one
    // refunds real money, so it's an explicit Void, never a quiet ✕.
    const removeCtl = p.locked
      ? '<span class="x" style="font-size:11px;font-weight:700">Void</span>'
      : '<span class="x">✕</span>';
    row.innerHTML = '<span>' + (p.label || p.method) + (p.locked && p.reference ? ' <span style="opacity:.55;font-size:11px">' + p.reference + '</span>' : '') + '</span>'
      + '<span class="amt">' + fmt(p.amount_cents) + '</span>'
      + removeCtl
      + (p.change_cents ? '<span class="chg">Change due ' + fmt(p.change_cents) + '</span>' : '');
    row.querySelector('.x').addEventListener('click', async () => {
      if (!p.locked) { cart.payments.splice(i, 1); renderSplit(); return; }
      if (!confirm('Void this ' + fmt(p.amount_cents) + ' card charge? The customer will be refunded.')) return;
      try {
        const res = await fetch(ROUTES.paymentIntentAutoRefund, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ payment_intent: p.stripe_payment_intent_id, reason: 'split_leg_voided' }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Refund failed');
        cart.payments.splice(i, 1); renderSplit();
      } catch (e) {
        alert('Void failed: ' + e.message + ' — check the Stripe dashboard for ' + p.stripe_payment_intent_id);
      }
    });
    list.appendChild(row);
  });
  const rem = splitRemaining();
  document.getElementById('splitRemain').textContent = fmt(rem);
  remRow.classList.toggle('zero', rem === 0);
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => {
    const t = b.dataset.tender;
    // MARKER-SPLIT-STRIPE — Stripe card now joins a split as the FINAL leg
    // (it charges exactly the remaining balance). Link + mark_paid wait.
    const stage2 = t === 'payment_link' || t === 'mark_paid';
    b.classList.toggle('split-disabled', active && stage2);
  });
  const confirmBtn = document.getElementById('tenderConfirmBtn');
  if (active) {
    confirmBtn.disabled = rem !== 0;
    confirmBtn.textContent = rem === 0 ? 'Complete' : fmt(rem) + ' remaining';
  } else {
    confirmBtn.textContent = 'Confirm';
  }
}
document.getElementById('splitAddBtn').addEventListener('click', () => {
  if (!cart.payment_method) return;
  const raw = document.getElementById('splitAmountInput').value;
  let c = Math.round(parseFloat(String(raw).replace(/[^0-9.]/g, '')) * 100);
  if (isNaN(c) || c <= 0) return;
  const rem = splitRemaining();
  if (rem === 0) return;
  let change = 0;
  if (cart.payment_method === 'cash' && c > rem) { change = c - rem; c = rem; }
  if (c > rem) c = rem;
  // MARKER-SPLIT-ANYORDER — a Stripe card leg charges immediately for the
  // typed amount; on success the row lands locked (real money moved).
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    SplitCard.pendingAmountCents = c;
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }
  const selBtn = document.querySelector('#tenderModal .reg-tender-btn.selected');
  cart.payments.push({
    method: cart.payment_method,
    amount_cents: c,
    change_cents: change,
    reference: (document.getElementById('tenderRefInput').value || '').trim() || null,
    label: selBtn ? selBtn.textContent.trim().split('\n')[0].trim() : cart.payment_method,
  });
  cart.payment_method = null;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('splitAmountRow').style.display = 'none';
  document.getElementById('splitHint').style.display = 'none';
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderRefInput').value = '';
  renderSplit();
});

document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.tender;
    // MARKER-SPLIT-ANYORDER — no ordering rules: any number of payments,
    // any order, any amount. Card gets the same amount field as everything.
    // MARKER-SPLIT-TENDER — amount entry for splittable tenders
    (function () {
      const t = btn.dataset.tender;
      const splittable = !(t === 'payment_link' || t === 'mark_paid'); // MARKER-SPLIT-ANYORDER
      const rowEl = document.getElementById('splitAmountRow');
      const hintEl = document.getElementById('splitHint');
      if (rowEl && splittable) {
        rowEl.style.display = 'flex'; if (hintEl) hintEl.style.display = '';
        const inp = document.getElementById('splitAmountInput');
        inp.value = (splitRemaining() / 100).toFixed(2);
        setTimeout(() => { inp.focus(); inp.select(); }, 30);
      } else if (rowEl) {
        rowEl.style.display = 'none'; if (hintEl) hintEl.style.display = 'none';
      }
    })();
    document.getElementById('tenderConfirmBtn').disabled = false;
    // MARKER-PATCH-170C — reference field only meaningful for checks now.
    // Card no longer needs a hand-typed reference (with direct payments the
    // brand+last4 becomes the reference automatically; without direct payments
    // the field was always low-value friction).
    const showRef = ['check'].includes(cart.payment_method);
    document.getElementById('tenderRefRow').style.display = showRef ? '' : 'none';

    // MARKER-PATCH-630 — manual tenders: show instructions + amount-prefilled link
    const manualRow = document.getElementById('tenderManualRow');
    if (btn.dataset.manual) {
      const total = ((calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents) - (calcRefundSubtotal() + calcRefundTax())) / 100;
      document.getElementById('tenderManualInstr').textContent = btn.dataset.instructions || '';
      const wrap = document.getElementById('tenderManualLinkWrap');
      if (btn.dataset.linktpl && total > 0) {
        const link = btn.dataset.linktpl.replace('{amount}', total.toFixed(2));
        document.getElementById('tenderManualLink').textContent = link;
        document.getElementById('tenderManualSms').href = 'sms:?&body=' + encodeURIComponent('Pay ' + btn.dataset.name + ': ' + link);
        wrap.style.display = '';
      } else {
        wrap.style.display = 'none';
      }
      manualRow.style.display = '';
    } else {
      manualRow.style.display = 'none';
    }
    renderTotals();
  });
});

// MARKER-PATCH-630 — copy the manual payment link
document.getElementById('tenderManualCopy').addEventListener('click', function () {
  const t = document.getElementById('tenderManualLink').textContent;
  navigator.clipboard.writeText(t).then(() => { this.textContent = 'Copied ✓'; setTimeout(() => { this.textContent = 'Copy link'; }, 1400); });
});

// MARKER-PATCH-170 — Direct Payments hand-keyed card flow
// When the card tender is selected AND the tenant has direct payments
// enabled, intercept to run the Stripe Payment Element BEFORE commit.
// Other tender types (cash, check, etc.) flow unchanged.
let DirectPay = {
  stripe: null,
  elements: null,
  paymentElement: null,
  clientSecret: null,
  paymentIntentId: null,
  inFlight: false,
};

// MARKER-PATCH-172 — Send-payment-link state
let PaymentLink = {
  saleId: null,
  sessionId: null,
  checkoutUrl: null,
  pollHandle: null,
};

// Show the Send-payment-link tender button when direct payments are enabled.
if (ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
  const btn = document.getElementById('tenderPaymentLinkBtn');
  if (btn) btn.style.display = '';
}

async function openCardPaymentModal() {
  // MARKER-PATCH-170B + 170C — pre-charge validation. The Charge button
  // pre-flight modal already catches this upstream, but defense-in-depth
  // in case openCardPaymentModal is reached via some other path.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    closeModal('tenderModal');
    openPreflightModal({
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service.',
      actionLabel: 'Add customer →',
      actionFn: () => { closeModal('preflightModal'); openCustomerModal(); },
    });
    return;
  }

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  errBox.textContent = '';
  document.getElementById('cardPaymentChargeBtn').disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = 'none';

  const totals = computeTotalsForCommit();
  // MARKER-SPLIT-ANYORDER — a pending split card leg charges the typed
  // amount; otherwise (single-tender card) the full total as always.
  const amountCents = SplitCard.pendingAmountCents != null
    ? SplitCard.pendingAmountCents
    : totals.total_cents + (cart.tipCents || 0);
  DirectPay.chargeAmountCents = amountCents;
  document.getElementById('cardPaymentAmount').textContent = fmt(amountCents);
  document.getElementById('cardPaymentChargeLabel').textContent = 'Charge ' + fmt(amountCents);

  openModal('cardPaymentModal');

  // Reset Stripe.js elements between opens
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.elements = null;
  DirectPay.paymentElement = null;
  DirectPay.clientSecret = null;
  DirectPay.paymentIntentId = null;

  // Create the PaymentIntent
  let intent;
  try {
    const res = await fetch(ROUTES.paymentIntentCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        // MARKER-PATCH-170B — preflight context
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
      }),
    });
    intent = await res.json();
    if (!intent.ok) throw new Error(intent.error || 'Could not initialize card payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    return;
  }

  DirectPay.clientSecret = intent.client_secret;
  DirectPay.paymentIntentId = intent.payment_intent;

  // Lazy-init Stripe.js with the tenant\'s publishable key
  if (!DirectPay.stripe) {
    DirectPay.stripe = Stripe(intent.publishable_key);
  }

  DirectPay.elements = DirectPay.stripe.elements({
    clientSecret: DirectPay.clientSecret,
    appearance: {
      theme: 'night',
      variables: {
        colorPrimary: '#BEF264',
        colorBackground: '#1c1c1c',
        colorText: '#f0f0f0',
        colorDanger: '#f87171',
        fontFamily: '-apple-system, BlinkMacSystemFont, sans-serif',
        borderRadius: '6px',
      },
    },
  });
  DirectPay.paymentElement = DirectPay.elements.create('payment', {
    layout: 'tabs',
  });
  DirectPay.paymentElement.mount('#card-payment-element');
  DirectPay.paymentElement.on('ready', () => {
    document.getElementById('cardPaymentChargeBtn').disabled = false;
  });
  DirectPay.paymentElement.on('change', (ev) => {
    document.getElementById('cardPaymentChargeBtn').disabled = !!ev.empty;
    if (ev.error) {
      errBox.textContent = ev.error.message;
      errBox.style.display = '';
    } else {
      errBox.style.display = 'none';
    }
  });
}

async function confirmCardPayment() {
  if (DirectPay.inFlight) return;
  DirectPay.inFlight = true;

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  const chargeBtn = document.getElementById('cardPaymentChargeBtn');
  chargeBtn.disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = '';

  let result;
  try {
    result = await DirectPay.stripe.confirmPayment({
      elements: DirectPay.elements,
      redirect: 'if_required',
    });
  } catch (e) {
    errBox.textContent = e.message || 'Payment failed.';
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  if (result.error) {
    errBox.textContent = result.error.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Verify with our server (Stripe is source of truth, not the client)
  let conf;
  try {
    const res = await fetch(ROUTES.paymentIntentConfirm, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: DirectPay.paymentIntentId }),
    });
    conf = await res.json();
    if (!conf.ok) throw new Error(conf.error || 'Could not verify payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Stash Stripe metadata for the sale commit
  cart.stripe_payment_intent_id = conf.payment_intent;
  cart.stripe_charge_id         = conf.stripe_charge_id;
  cart.card_brand               = conf.card_brand;
  cart.card_last4               = conf.card_last4;
  cart.card_funding             = conf.card_funding;
  cart.payment_reference        = (conf.card_brand && conf.card_last4)
    ? (conf.card_brand + ' ····' + conf.card_last4)
    : null;

  // MARKER-SPLIT-ANYORDER — a split card charge lands as a LOCKED row (the
  // charge is live; removal is an explicit Void). Remainder left → reopen
  // the tender modal and keep taking payments, any order. Covered → commit.
  if (SplitCard.pendingAmountCents != null) {
    cart.payments.push({
      method: 'card',
      amount_cents: DirectPay.chargeAmountCents,
      change_cents: 0,
      reference: cart.payment_reference,
      label: 'Card',
      locked: true,
      stripe_payment_intent_id: conf.payment_intent,
    });
    SplitCard.pendingAmountCents = null;
    cart.payment_method = null;
    cart.payment_reference = null;
    closeModal('cardPaymentModal');
    DirectPay.inFlight = false;
    if (splitRemaining() > 0) {
      renderSplit();
      openModal('tenderModal');
      return;
    }
    cart.payment_method = 'split';
    commitTransaction({});
    return;
  }

  // Close modal and run the existing commit pipeline.
  // MARKER-PATCH-170B — wrap commit in our own try; if commitTransaction
  // shows the failure banner, we still hold the PI in cart.stripe_payment_intent_id.
  // commitTransaction itself calls autoRefundOnCommitFailure() if its commit fails.
  closeModal('cardPaymentModal');
  DirectPay.inFlight = false;
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
}

// MARKER-PATCH-170B — called by commitTransaction's error path when the
// charge has already authorized but the commit step failed. Refunds the
// PaymentIntent server-side and clears the Stripe metadata from the cart
// so the user doesn\'t double-charge.
async function autoRefundOnCommitFailure(reason) {
  if (!cart.stripe_payment_intent_id) return;
  const pi = cart.stripe_payment_intent_id;
  // Optimistically clear from cart so a retry doesn\'t re-send the stale PI
  cart.stripe_payment_intent_id = null;
  cart.stripe_charge_id = null;
  cart.card_brand = null;
  cart.card_last4 = null;
  cart.card_funding = null;
  cart.payment_reference = null;

  try {
    const res = await fetch(ROUTES.paymentIntentAutoRefund, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: pi, reason: reason || 'commit_failed' }),
    });
    const data = await res.json();
    const banner = document.getElementById('errBanner');
    if (data.ok) {
      banner.textContent = (banner.textContent || '') + ' The card charge was automatically refunded.';
    } else {
      banner.textContent = (banner.textContent || '') + ' WARNING: card was charged but refund failed — check Stripe dashboard for payment intent ' + pi;
    }
    banner.style.display = '';
  } catch (e) {
    const banner = document.getElementById('errBanner');
    banner.textContent = 'Card was charged but refund attempt failed. Stripe payment intent: ' + pi + '. Please refund manually in Stripe dashboard.';
    banner.style.display = '';
  }
}

document.getElementById('cardPaymentCancelBtn').addEventListener('click', () => {
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.inFlight = false;
  closeModal('cardPaymentModal');
});
document.getElementById('cardPaymentChargeBtn').addEventListener('click', confirmCardPayment);

// Helper used by openCardPaymentModal to compute the current charge total.
// Mirrors the math in commitTransaction\'s totals without firing a save.
function computeTotalsForCommit() {
  const sub = calcSubtotal();
  const tax = Math.round(sub * (CFG.taxRate || 0));
  const total = sub + tax - (cart.discountCents || 0);
  return { subtotal_cents: sub, tax_cents: tax, total_cents: Math.max(0, total) };
}

document.getElementById('tenderConfirmBtn').addEventListener('click', () => {
  cart.payment_reference = document.getElementById('tenderRefInput').value.trim() || null;

  // MARKER-SPLIT-TENDER — split path: tenders recorded row by row; the tip
  // modal is skipped (set tips before splitting so remaining math is stable).
  if (cart.payments.length > 0) {
    if (splitRemaining() !== 0) return;
    cart.payment_method = 'split';
    cart.payment_reference = null;
    closeModal('tenderModal');
    commitTransaction({});
    return;
  }

  // MARKER-PATCH-170 — Direct Payments path
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }

  // MARKER-PATCH-172 — Send-payment-link path
  if (cart.payment_method === 'payment_link' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openPaymentLinkModal();
    return;
  }

  // Default path (cash, check, store_credit, mark_paid, or card-without-Stripe)
  closeModal('tenderModal');
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
});

// MARKER-PATCH-172 — Send-payment-link modal flow
async function openPaymentLinkModal() {
  const statusText = document.getElementById('paymentLinkStatusText');
  statusText.textContent = 'Creating payment link…';
  document.getElementById('paymentLinkQR').innerHTML = '';
  document.getElementById('paymentLinkUrl').textContent = '';
  openModal('paymentLinkModal');

  const totals = computeTotalsForCommit();
  const amountCents = totals.total_cents + (cart.tipCents || 0);
  document.getElementById('paymentLinkAmountValue').textContent = fmt(amountCents);

  let resp;
  try {
    const res = await fetch(ROUTES.checkoutSessionCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
        description: 'Purchase at ' + document.title,
        items: cart.items.map(serializeLine),
        tip_cents: cart.tipCents || 0,
        discount_cents: cart.discountCents || 0,
        sale_id: cart.draft_id || null,
      }),
    });
    resp = await res.json();
    if (!resp.ok) throw new Error(resp.error || 'Could not create payment link.');
  } catch (e) {
    closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
    showError(e.message);
    return;
  }

  PaymentLink.saleId = resp.sale_id;
  PaymentLink.sessionId = resp.session_id;
  PaymentLink.checkoutUrl = resp.checkout_url;
  DisplayMirror.payUrl = resp.checkout_url; // MARKER-REGISTER-RECON-DISPLAY
  queueDisplayMirror(true);

  // Render QR code
  const qrEl = document.getElementById('paymentLinkQR');
  qrEl.innerHTML = '';
  if (typeof qrcode === 'function') {
    const qr = qrcode(0, 'L');
    qr.addData(resp.checkout_url);
    qr.make();
    qrEl.innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
    // Constrain SVG size
    const svg = qrEl.querySelector('svg');
    if (svg) { svg.style.width = '200px'; svg.style.height = '200px'; }
  } else {
    qrEl.textContent = '(QR library failed to load. Use the URL below.)';
  }

  document.getElementById('paymentLinkUrl').textContent = resp.checkout_url;
  document.getElementById('paymentLinkStatusText').textContent = 'Waiting for customer to pay…';

  // Start polling
  startPaymentLinkPolling();
}

function startPaymentLinkPolling() {
  stopPaymentLinkPolling();
  PaymentLink.pollHandle = setInterval(checkPaymentLinkStatus, 3000);
}
function stopPaymentLinkPolling() {
  if (PaymentLink.pollHandle) {
    clearInterval(PaymentLink.pollHandle);
    PaymentLink.pollHandle = null;
  }
}

async function checkPaymentLinkStatus() {
  if (!PaymentLink.saleId) return;
  try {
    const res = await fetch(ROUTES.checkoutSessionCheck, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: PaymentLink.saleId }),
    });
    const data = await res.json();
    if (!data.ok) return;

    if (data.status === 'succeeded') {
      stopPaymentLinkPolling();
      closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
      // Show the receipt screen using the existing flow
      showReceipt({ sale_number: data.sale_number, total_cents: data.total_cents, sale_id: data.sale_id }); // MARKER-PATCH-322
      // Clear cart since the sale completed
      cart.draft_id = null;
      cart.customer = null;
      cart.items = [];
      cart.refund_lines = [];
      cart.tipCents = 0;
      cart.discountCents = 0;
      renderAll();
      PaymentLink.saleId = null;
      PaymentLink.sessionId = null;
      PaymentLink.checkoutUrl = null;
      return;
    }

    if (data.status === 'expired') {
      stopPaymentLinkPolling();
      document.getElementById('paymentLinkStatusText').textContent = 'Link expired. Cancel and try again.';
    }
  } catch (e) {
    // Transient network errors — keep polling.
  }
}

document.getElementById('paymentLinkCopyBtn').addEventListener('click', () => {
  if (!PaymentLink.checkoutUrl) return;
  navigator.clipboard.writeText(PaymentLink.checkoutUrl).then(() => {
    const btn = document.getElementById('paymentLinkCopyBtn');
    const orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  });
});

// MARKER-PATCH-192 — "Cancel link": explicit destructive action. Expires the
// Stripe session and marks the sale cancelled. Only fires on deliberate click.
document.getElementById('paymentLinkCancelBtn').addEventListener('click', async () => {
  if (!confirm('Cancel this payment link? The customer will no longer be able to pay it.')) return;
  stopPaymentLinkPolling();
  if (PaymentLink.saleId) {
    try {
      await fetch(ROUTES.checkoutSessionCancel, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ sale_id: PaymentLink.saleId }),
      });
    } catch (e) {}
  }
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
});

// MARKER-PATCH-192 — "Done — keep link live": the operator steps away while the
// customer pays on their own time. Stops the foreground poll and closes the
// modal, but leaves the sale PENDING and the Stripe session active. The webhook
// will promote it when the customer pays; the appointment surfaces the pending
// state so it's never lost. Does NOT cancel anything.
document.getElementById('paymentLinkDoneBtn').addEventListener('click', () => {
  stopPaymentLinkPolling();
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
});

function openTipModal() {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  const grid = document.getElementById('tipGrid');
  grid.innerHTML = '';
  const sub = calcSubtotal();
  (CFG.tipOptions || []).forEach(opt => {
    let cents, label;
    if (CFG.tipMethod === 'percent') {
      cents = Math.round(sub * (parseFloat(opt) / 100));
      label = `${opt}% (${fmt(cents)})`;
    } else {
      cents = Math.round(parseFloat(opt) * 100);
      label = fmt(cents);
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'reg-tip-btn';
    btn.textContent = label;
    btn.addEventListener('click', () => {
      document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      cart.tipCents = cents;
      document.getElementById('tipCustomInput').value = '';
    });
    grid.appendChild(btn);
  });
  openModal('tipModal');
}

document.getElementById('tipCustomInput').addEventListener('input', () => {
  const v = parseFloat(document.getElementById('tipCustomInput').value);
  if (!isNaN(v) && v >= 0) {
    cart.tipCents = Math.round(v * 100);
    document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
  }
});
document.getElementById('tipClearBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
});
document.getElementById('tipSkipBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  closeModal('tipModal');
  commitTransaction({});
});
document.getElementById('tipConfirmBtn').addEventListener('click', () => {
  closeModal('tipModal');
  commitTransaction({});
});

async function commitTransaction(opts = {}) {
  document.getElementById('payBtn').disabled = true;
  document.getElementById('errBanner').style.display = 'none';

  // Make sure any pending or in-flight draft save lands before commit.
  await flushDraftSave();

  const hasRefund = cart.refund_lines.length > 0;
  const hasNewSale = cart.items.length > 0;

  try {
    let url, payload;

    if (hasRefund) {
      // Mixed or pure-refund transaction — use the new endpoint that handles both.
      url = ROUTES.commitTxn;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
        items: hasNewSale ? cart.items.map(serializeLine) : [],
        refund: {
          original_sale_id: cart.refund_meta.original_sale_id,
          // MARKER-REFUND-QTY — the quantity the cashier chose is now sent and
          // is authoritative on the server, along with where the goods went.
          items: cart.refund_lines.map(r => ({
            sale_item_id: r.original_item_id,
            quantity: r.qty,
            disposition: r.disposition || 'restock',
          })),
          item_ids: cart.refund_lines.map(r => r.original_item_id),
          refund_method: cart.payment_method,
        },
      };
    } else if (cart.draft_id) {
      // Draft-backed pure sale — promote draft to paid (existing path).
      url = ROUTES.commitDraft + '/' + cart.draft_id + '/commit';
      payload = {
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        tip_cents: cart.tipCents,
        customer_id: cart.customer ? cart.customer.id : null,
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    } else {
      // Fallback path — pure sale, no draft, send full cart.
      url = ROUTES.storeSale;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        discount_cents: cart.discountCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        items: cart.items.map(serializeLine),
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    }

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      // MARKER-PATCH-170B — auto-refund the card if we authorized one
      if (cart.stripe_payment_intent_id) {
        await autoRefundOnCommitFailure(data.error || 'commit_failed');
      }
      showError(data.error || 'Could not complete the transaction.');
      return;
    }
    showReceipt(data);
  } catch (e) {
    // MARKER-OFFLINE-SYNC — network failure: queue the sale on-device when eligible.
    if (await osTryQueueCommit()) return;
    showError('Network error. Please try again.');
  } finally {
    document.getElementById('payBtn').disabled = (cart.items.length === 0 && cart.refund_lines.length === 0);
  }
}

function serializeLine(i) {
  const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
  if (i.type === 'product') out.inventory_item_id = i.source_id;
  if (i.type === 'service') out.service_id = i.source_id;
  if (i.type === 'open_item') {
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
  }
  return out;
}

function showError(msg) {
  const el = document.getElementById('errBanner');
  el.textContent = msg;
  el.style.display = '';
  // MARKER-PATCH-170C — shake to draw attention, even on repeat errors.
  // Re-trigger by removing then re-adding the class on the next frame.
  el.classList.remove('reg-err--shake');
  requestAnimationFrame(() => {
    requestAnimationFrame(() => { el.classList.add('reg-err--shake'); });
  });
}

// MARKER-PATCH-170C — pre-flight cart validation.
// Returns null if the cart is commit-able, or a blocker object
// { title, message, actionLabel, actionFn } describing what's wrong.
// Order matters: surface the most-actionable problem first.
function preflightCheck() {
  // Service-line-without-customer is the only blocker we know about today.
  // More can be added (e.g. price-zero items, missing location) without
  // changing the call site.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    return {
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service. Attach a customer and we\'ll continue.',
      actionLabel: 'Add customer →',
      actionFn: () => {
        closeModal('preflightModal');
        openCustomerModal();
      },
    };
  }
  return null;
}

function openPreflightModal(blocker) {
  document.getElementById('preflightTitle').textContent = blocker.title;
  document.getElementById('preflightLede').textContent  = blocker.message;
  const btn = document.getElementById('preflightActionBtn');
  btn.textContent = blocker.actionLabel;
  // Replace previous click handler — clone the node to drop bound listeners.
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', blocker.actionFn);
  openModal('preflightModal');
}
// MARKER-PATCH-187 — after a completed sale the receipt sits briefly, then the
// register auto-resets to a fresh state. A visible countdown shows it coming;
// clicking "New sale" (or any cart interaction) resets immediately and cancels
// the timer.
const RECEIPT_AUTO_RESET_SECONDS = 45;
let receiptResetTimer = null;
let receiptCountdownTimer = null;
let receiptSaleId = null;        // MARKER-PATCH-322
let receiptCustomerEmail = null; // MARKER-PATCH-322

function clearReceiptTimers() {
  if (receiptResetTimer) { clearTimeout(receiptResetTimer); receiptResetTimer = null; }
  if (receiptCountdownTimer) { clearInterval(receiptCountdownTimer); receiptCountdownTimer = null; }
}

async function resetRegisterToFresh() {
  clearReceiptTimers();
  cart.draft_id = null;
  cart.customer = null;
  cart.items = [];
  cart.refund_lines = [];
  cart.refund_meta = null;
  cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  closeModal('receiptModal');
  renderCart();
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  refreshDraftsBanner(await loadDrafts());
}

function showReceipt(data) {
  document.getElementById('receiptNum').textContent = data.sale_number;
  document.getElementById('receiptTotal').textContent = fmt(data.total_cents);
  openModal('receiptModal');

  // MARKER-PATCH-322 — capture the sale for print/email before the cart clears.
  receiptSaleId = data.sale_id || null;
  receiptCustomerEmail = (typeof cart !== 'undefined' && cart && cart.customer && cart.customer.email) ? cart.customer.email : null;
  var _rPrint = document.getElementById('receiptPrintBtn');
  var _rEmail = document.getElementById('receiptEmailBtn');
  var _rPrompt = document.getElementById('receiptEmailPrompt');
  var _rMsg = document.getElementById('receiptEmailMsg');
  if (_rPrint) _rPrint.style.display = receiptSaleId ? '' : 'none';
  if (_rEmail) _rEmail.style.display = receiptSaleId ? '' : 'none';
  if (_rPrompt) _rPrompt.style.display = 'none';
  if (_rMsg) { _rMsg.style.display = 'none'; _rMsg.textContent = ''; }

  // MARKER-PATCH-232B — round-trip receipts: when the register was opened
  // with a return_to, the receipt offers (and the countdown takes) the way
  // back instead of resetting to a fresh register.
  const backBtn = document.getElementById('receiptBackTo');
  if (backBtn) {
    if (window.registerReturnTo) {
      backBtn.href = window.registerReturnTo;
      backBtn.style.display = '';
      backBtn.textContent = 'Back to where you were →';
      const autoEl = document.getElementById('receiptAutoReset');
      if (autoEl) autoEl.innerHTML = 'Heading back in <span id="receiptCountdown">45</span>s';
    } else {
      backBtn.style.display = 'none';
    }
  }

  // Start the auto-reset countdown.
  clearReceiptTimers();
  let remaining = RECEIPT_AUTO_RESET_SECONDS;
  const countdownEl = document.getElementById('receiptCountdown');
  if (countdownEl) countdownEl.textContent = remaining;
  receiptCountdownTimer = setInterval(() => {
    remaining -= 1;
    if (countdownEl) countdownEl.textContent = Math.max(0, remaining);
    if (remaining <= 0) clearInterval(receiptCountdownTimer);
  }, 1000);
  receiptResetTimer = setTimeout(() => {
    if (window.registerReturnTo) { window.location.href = window.registerReturnTo; return; }
    resetRegisterToFresh();
  }, RECEIPT_AUTO_RESET_SECONDS * 1000);
}

document.getElementById('receiptNewSale').addEventListener('click', () => { resetRegisterToFresh(); });

// MARKER-PATCH-322 — print + email the just-completed receipt.
(function () {
  var printBtn = document.getElementById('receiptPrintBtn');
  var emailBtn = document.getElementById('receiptEmailBtn');
  var promptEl = document.getElementById('receiptEmailPrompt');
  var inputEl  = document.getElementById('receiptEmailInput');
  var sendEl   = document.getElementById('receiptEmailSend');
  var msgEl    = document.getElementById('receiptEmailMsg');

  // Stop the auto-reset countdown once the cashier interacts here.
  function holdReset() {
    try { clearReceiptTimers(); } catch (e) {}
    var a = document.getElementById('receiptAutoReset');
    if (a) a.style.display = 'none';
  }

  if (printBtn) printBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (window.openPrintComposer) { window.openPrintComposer('sale', receiptSaleId, { type: 'receipt', format: 't80' }); return; } // MARKER-PATCH-338
    if (!ROUTES.saleReceipt) return;
    var url = ROUTES.saleReceipt.replace('__ID__', encodeURIComponent(receiptSaleId)) + '?embed=1';
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = url;
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url.replace('?embed=1', ''), '_blank'); }
      setTimeout(function () { f.remove(); }, 2000);
    };
    document.body.appendChild(f);
  });

  function sendReceipt(email) {
    if (!receiptSaleId || !ROUTES.resendReceipt) return;
    var url = ROUTES.resendReceipt.replace('__ID__', encodeURIComponent(receiptSaleId));
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (sendEl) sendEl.disabled = true;
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      body: email ? ('email=' + encodeURIComponent(email)) : ''
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (sendEl) sendEl.disabled = false;
      if (d && d.ok) {
        var to = email || receiptCustomerEmail;
        if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Receipt sent' + (to ? ' to ' + to : '') + '.'; }
        if (promptEl) promptEl.style.display = 'none';
      } else if (msgEl) {
        msgEl.style.display = ''; msgEl.textContent = (d && d.error) || 'Could not send receipt.';
      }
    })
    .catch(function () { if (sendEl) sendEl.disabled = false; if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Could not send receipt.'; } });
  }

  if (emailBtn) emailBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (receiptCustomerEmail) { sendReceipt(null); }
    else { if (promptEl) promptEl.style.display = 'flex'; if (inputEl) inputEl.focus(); }
  });
  if (sendEl) sendEl.addEventListener('click', function () {
    var v = ((inputEl && inputEl.value) || '').trim();
    if (!v || v.indexOf('@') < 0) { if (inputEl) inputEl.focus(); return; }
    sendReceipt(v);
  });
  if (inputEl) inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (sendEl) sendEl.click(); } });
})();

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
});

// --- Drafts banner / resume / discard ---
async function loadDrafts() {
  try {
    const res = await fetch(ROUTES.listDrafts, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    return data.drafts || [];
  } catch (e) {
    return [];
  }
}

function refreshDraftsBanner(drafts) {
  const banner = document.getElementById('draftsBanner');
  // Filter out the current cart's own draft from the count.
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) { banner.style.display = 'none'; return; }
  const word = others.length === 1 ? 'draft' : 'drafts';
  document.getElementById('draftsBannerLabel').textContent =
    others.length + ' open ' + word + ' at this location';
  banner.style.display = '';
}

function fmtAge(iso) {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const now = Date.now();
  const mins = Math.floor((now - then) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return mins + 'm ago';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + 'h ago';
  return Math.floor(hrs / 24) + 'd ago';
}

function renderDraftsList(drafts) {
  const list = document.getElementById('draftsList');
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) {
    list.innerHTML = '<div class="reg-empty">No other open drafts.</div>';
    return;
  }
  list.innerHTML = others.map(d => {
    const itemWord = d.item_count === 1 ? 'item' : 'items';
    const meta = [
      d.item_count + ' ' + itemWord,
      d.customer || 'no customer',
      d.started_by ? 'by ' + d.started_by : null,
      fmtAge(d.updated_at),
    ].filter(Boolean).join(' · ');
    return '<div class="reg-draft-row" data-id="' + d.id + '">' +
      '<div>' +
        '<div style="font-weight:500">' + escapeHtml(d.customer || 'Walk-in') + '</div>' +
        '<div class="meta-line">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<div class="total">' + fmt(d.total_cents) + '</div>' +
      '<div class="actions">' +
        '<button type="button" class="btn-resume" data-resume="' + d.id + '">Resume</button>' +
        '<button type="button" class="btn-discard" data-discard="' + d.id + '">Discard</button>' +
      '</div>' +
    '</div>';
  }).join('');
  list.querySelectorAll('[data-resume]').forEach(btn => {
    btn.addEventListener('click', () => resumeDraft(btn.dataset.resume));
  });
  list.querySelectorAll('[data-discard]').forEach(btn => {
    btn.addEventListener('click', () => discardDraftFromList(btn.dataset.discard));
  });
}

document.getElementById('draftsBanner').addEventListener('click', async () => {
  const drafts = await loadDrafts();
  renderDraftsList(drafts);
  openModal('draftsModal');
});

async function resumeDraft(id) {
  if (cart.items.length > 0) {
    const ok = await confirmDialog(
      'Your current cart will be replaced with this draft.',
      'Replace cart',
      'Replace current cart?'
    );
    if (!ok) return;
  }
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not load draft.'); closeModal('draftsModal'); return; }
    // Cancel any pending save for the OLD cart before we overwrite state.
    clearTimeout(draftSaveTimer);
    draftSaveTimer = null;
    cart.draft_id = data.draft.id;
    cart.customer = data.draft.customer;
    cart.tipCents = data.draft.tip_cents || 0;
    cart.tax_locked = !!data.draft.tax_locked;
    cart.items = (data.draft.items || []).map(i => ({
      key: ++lineKey,
      type: i.type,
      source_id: i.source_id,
      name: i.name,
      price_cents: i.price_cents,
      qty: i.qty,
      is_taxable: i.is_taxable,
      tax_cents: i.tax_cents || 0,
      tax_rate_snapshot: i.tax_rate_snapshot,
    }));
    closeModal('draftsModal');
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error loading draft.');
    closeModal('draftsModal');
  }
}

async function discardDraftFromList(id) {
  const ok = await confirmDialog(
    'This draft will be permanently deleted.',
    'Discard draft',
    'Discard this draft?'
  );
  if (!ok) return;
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {
      method: 'DELETE',
      headers: {'Accept':'application/json', 'X-CSRF-TOKEN': CSRF},
    });
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not discard draft.'); return; }
    // If we just discarded the cart's own draft, clear it too.
    if (cart.draft_id === id) {
      cart.draft_id = null;
      cart.items = [];
      cart.customer = null;
      cart.tipCents = 0;
      renderCart();
    }
    const drafts = await loadDrafts();
    renderDraftsList(drafts);
    refreshDraftsBanner(drafts);
  } catch (e) {
    showError('Network error discarding draft.');
  }
}

renderCart();

// Auto-load a draft from ?draft=X in the URL. Used by the cash-pays-for-class
// flow in ClassController::registerViaCash, which prepares a drop-in cart and
// redirects here so the admin can take payment. Removes the param after load
// so a refresh doesn't re-trigger.
(function autoloadDraftFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const draftId = params.get('draft');
  if (!draftId) return;
  // Strip the param so this only fires once.
  params.delete('draft');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  resumeDraft(draftId);
})();

// MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
// "Payment link sent" banner via ?status=<sale_id>. Shows a live timeline of
// the outstanding link, polls for resolution, and offers copy / cancel.
const LinkStatus = { saleId: null, sessionId: null, url: null, poll: null };

function lsRenderTimeline(sale, liveStatus) {
  const paid = (liveStatus === 'succeeded') || sale.payment_status === 'paid' || (sale.paid_cents > 0);
  const expired = (liveStatus === 'expired') || sale.sale_status === 'cancelled';
  const created = sale.created_at ? lsFmtDate(sale.created_at) : '';
  const rows = [];
  rows.push(['done', 'Link created', created]);
  rows.push(['done', 'Link sent to customer', sale.customer && sale.customer.email ? sale.customer.email : '']);
  if (paid) {
    rows.push(['done', 'Payment received', sale.paid_at ? lsFmtDate(sale.paid_at) : '']);
    rows.push(['done', 'Recorded to ledger', sale.payments && sale.payments.length ? (sale.payments[0].method_label || 'card') : '']);
  } else if (expired) {
    rows.push(['', 'Link expired without payment', '']);
  } else {
    rows.push(['now', 'Awaiting payment', 'checking automatically…']);
    rows.push(['', 'Payment received', '— pending —']);
    rows.push(['', 'Recorded to ledger', '— pending —']);
  }
  return rows.map(r =>
    '<div class="ls-te ' + r[0] + '"><div class="tt">' + esc(r[1]) + '</div>' +
    (r[2] ? '<div class="td">' + esc(r[2]) + '</div>' : '') + '</div>'
  ).join('');
}

function lsSetPill(status) {
  const el = document.getElementById('lsStatusPill');
  if (status === 'succeeded' || status === 'paid') { el.className = 'ls-pill paid'; el.textContent = 'Paid'; }
  else if (status === 'expired') { el.className = 'ls-pill expired'; el.textContent = 'Expired'; }
  else { el.className = 'ls-pill pending'; el.textContent = 'Awaiting payment'; }
}

function lsFmtDate(iso){ if(!iso) return ''; const d=new Date(iso); if(isNaN(d.getTime())) return iso; return d.toLocaleString(undefined,{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}); }
function esc(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function openLinkStatus(saleId) {
  if (!saleId) return;
  LinkStatus.saleId = saleId;
  openModal('linkStatusModal');
  document.getElementById('lsHeader').textContent = 'Loading…';
  document.getElementById('lsTimeline').innerHTML = '';
  // Fetch the sale detail (showSaleJson — includes checkout + payments).
  let sale = null;
  try {
    const showUrl = ROUTES.saleShow ? ROUTES.saleShow.replace('__ID__', encodeURIComponent(saleId)) : null;
    if (showUrl) {
      const r = await fetch(showUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok) sale = d.sale;
    }
  } catch (e) {}
  if (!sale) { document.getElementById('lsHeader').textContent = 'Could not load this sale.'; return; }

  const status = (sale.payment_status === 'paid' || sale.paid_cents > 0) ? 'paid'
               : (sale.sale_status === 'cancelled' ? 'expired' : 'pending');
  lsSetPill(status);
  document.getElementById('lsHeader').innerHTML =
    fmt(sale.total_cents) + ' · ' + esc(sale.customer ? sale.customer.name : 'No customer') +
    (sale.sale_number ? ' · <span style="font-family:var(--ia-font-mono);font-size:11px">' + esc(sale.sale_number) + '</span>' : '');
  document.getElementById('lsTimeline').innerHTML = lsRenderTimeline(sale, status === 'paid' ? 'succeeded' : (status === 'expired' ? 'expired' : 'pending'));

  // Cancel-link action only while still pending.
  const cancelBtn = document.getElementById('lsCancelLinkBtn');
  cancelBtn.style.display = (status === 'pending') ? '' : 'none';

  // Poll for resolution while pending.
  if (LinkStatus.poll) clearInterval(LinkStatus.poll);
  if (status === 'pending') {
    LinkStatus.poll = setInterval(async () => {
      try {
        const res = await fetch(ROUTES.checkoutSessionCheck, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ sale_id: saleId }),
        });
        const d = await res.json();
        if (!d.ok) return;
        if (d.status === 'succeeded' || d.status === 'expired') {
          clearInterval(LinkStatus.poll); LinkStatus.poll = null;
          openLinkStatus(saleId); // re-render terminal state
        }
      } catch (e) {}
    }, 4000);
  }
}

function lsClose() {
  if (LinkStatus.poll) { clearInterval(LinkStatus.poll); LinkStatus.poll = null; }
  closeModal('linkStatusModal');
}

document.getElementById('lsCloseBtn').addEventListener('click', lsClose);
document.getElementById('lsCancelLinkBtn').addEventListener('click', async () => {
  if (!LinkStatus.saleId) return;
  if (!confirm('Cancel this payment link? The customer will no longer be able to pay it.')) return;
  try {
    await fetch(ROUTES.checkoutSessionCancel, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: LinkStatus.saleId }),
    });
  } catch (e) {}
  lsClose();
});

// Autoload from ?status=<sale_id> (from the appointment banner).
(function autoloadStatusFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const sid = params.get('status');
  if (!sid) return;
  params.delete('status');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  openLinkStatus(sid);
})();

// --- Refund picker ---
let refundPickerSale = null;  // the full sale object from lookupSale, kept while modal is open

async function openRefundPicker(saleId) {
  // We don't have a per-id endpoint yet; the sale_number-based lookup is what we have.
  // Re-trigger the search to get fresh data (cheap — last query is still in input).
  const q = searchInput.value.trim();
  if (!q || !looksLikeSaleNumber(q)) {
    showError('Could not load sale. Try searching the sale number again.');
    return;
  }
  try {
    const url = new URL(ROUTES.lookupSale, window.location.origin);
    url.searchParams.set('sale_number', normalizeSaleNumber(q));
    const r = await fetch(url, {headers: {'Accept': 'application/json'}});
    const d = await r.json();
    if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
    refundPickerSale = d.sale;
    renderRefundPicker();
    openModal('refundModal');
  } catch (e) {
    showError('Network error loading sale.');
  }
}

// Auto-open the refund picker when arriving from the sale-detail modal's
// "Refund this sale" button (?refund=SALE_NUMBER). Looks the sale up by
// number directly so it doesn't depend on the search input being populated.
(function autoloadRefundFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const saleNumber = params.get('refund');
  if (!saleNumber) return;
  // Strip the param so a refresh doesn't re-trigger.
  params.delete('refund');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);

  (async () => {
    try {
      const url = new URL(ROUTES.lookupSale, window.location.origin);
      url.searchParams.set('sale_number', saleNumber);
      const r = await fetch(url, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
      refundPickerSale = d.sale;
      renderRefundPicker();
      openModal('refundModal');
    } catch (e) {
      showError('Network error loading sale.');
    }
  })();
})();

function renderRefundPicker() {
  const sale = refundPickerSale;
  if (!sale) return;
  document.getElementById('refundModalLede').textContent =
    'Sale #' + sale.sale_number + (sale.customer ? ' · ' + sale.customer : '') + ' · ' + fmt(sale.total_cents);

  const list = document.getElementById('refundList');
  if (!sale.items.length) {
    list.innerHTML = '<div class="reg-empty">No items on this sale.</div>';
    return;
  }
  list.innerHTML = sale.items.map((it, idx) => {
    const disabled = it.remaining <= 0;
    const meta = disabled
      ? 'fully refunded'
      : (it.already_refunded > 0 ? it.already_refunded + ' of ' + it.quantity + ' already refunded · ' + it.remaining + ' available' : it.quantity + ' available');
    return '<div class="reg-refund-row ' + (disabled ? 'disabled' : '') + '" data-idx="' + idx + '">' +
      '<input type="checkbox" data-pick="' + idx + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div>' +
        '<div class="name">' + escapeHtml(it.name) + '</div>' +
        '<div class="meta">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<input type="number" class="qty-input" data-qty="' + idx + '" min="0" max="' + it.remaining + '" step="1" value="' + it.remaining + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div class="total">' + fmt(it.unit_price_cents) + '</div>' +
    '</div>';
  }).join('');

  // Wire checkbox + qty change to update the Add button state.
  list.querySelectorAll('[data-pick]').forEach(cb => cb.addEventListener('change', updateRefundAddBtn));
  list.querySelectorAll('[data-qty]').forEach(inp => inp.addEventListener('input', updateRefundAddBtn));
  updateRefundAddBtn();
}

function updateRefundAddBtn() {
  const list = document.getElementById('refundList');
  let anyChecked = false;
  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = cb.dataset.pick;
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (qty > 0) anyChecked = true;
  });
  document.getElementById('refundAddBtn').disabled = !anyChecked;
}

document.getElementById('refundAddBtn').addEventListener('click', () => {
  const sale = refundPickerSale;
  if (!sale) return;
  const list = document.getElementById('refundList');

  // If cart already has refund lines from a different sale, block.
  if (cart.refund_meta && cart.refund_meta.original_sale_id !== sale.id) {
    showError('Cart already has refund lines from a different sale. Discard or commit those first.');
    return;
  }

  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = parseInt(cb.dataset.pick, 10);
    const item = sale.items[idx];
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (!qty || qty <= 0) return;
    // Tax on a partial refund is a proportional share of original line tax.
    const fullQty = item.quantity || 1;
    const taxShare = item.tax_cents
      ? Math.round((item.tax_cents * qty) / fullQty)
      : 0;
    cart.refund_lines.push({
      key: ++lineKey,
      original_sale_id:  sale.id,
      original_item_id:  item.id,
      type:              item.type,
      name:              item.name,
      qty:               qty,
      price_cents:       item.unit_price_cents,
      tax_cents:         taxShare,
      is_taxable:        !!item.is_taxable,
    });
  });

  if (cart.refund_lines.length > 0 && !cart.refund_meta) {
    cart.refund_meta = {
      original_sale_id:    sale.id,
      original_sale_number: sale.sale_number,
      refund_method:       null,  // resolved at tender time
    };
  }

  closeModal('refundModal');
  refundPickerSale = null;
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  renderCart();
  searchInput.focus();
});

// On page load, populate the banner.
loadDrafts().then(refreshDraftsBanner);

// If we were redirected here from the Quotes page with ?resume=<id>,
// load that quote into the cart automatically.
(function () {
  const params = new URLSearchParams(window.location.search);
  // MARKER-PATCH-232B — capture return_to BEFORE replaceState wipes the
  // query string. Local paths only; anything else is ignored.
  const rawReturnTo = params.get('return_to') || '';
  window.registerReturnTo = (rawReturnTo.startsWith('/') && !rawReturnTo.startsWith('//')) ? rawReturnTo : null;
  const resumeId = params.get('resume');
  if (!resumeId) return;
  // Strip the param from the URL so a refresh doesn't re-trigger.
  const cleanUrl = window.location.pathname;
  window.history.replaceState({}, '', cleanUrl);
  // Reuse the existing resumeDraft path — it handles drafts and quotes both.
  resumeDraft(resumeId);
})();

/* ===================================================================
   Appointment tray — lazy-loads on click. Lists every pending sale
   that came from a completed appointment, lets staff jump to one.
   =================================================================== */
(function () {
  var toggle = document.getElementById('appointment-tray-toggle');
  var listEl = document.getElementById('appointment-tray-list');
  if (!toggle || !listEl) return;

  var loaded = false;
  var open = false;

  toggle.addEventListener('click', function () {
    if (!loaded) {
      fetch('{{ route("tenant.register.appointment-tray", ["subdomain" => tenant()->subdomain]) }}', {
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok || !data.sales || !data.sales.length) {
            listEl.innerHTML = '<div style="padding:14px;font-size:12px;color:var(--ia-text-dim);text-align:center">No pending appointment sales.</div>';
            return;
          }
          listEl.innerHTML = data.sales.map(function (s) {
            // MARKER-PATCH-180 — row carries data-sale-id; a × dismiss button
            // removes the parked draft from the tray. Resume happens on the
            // row body (not the buttons), wired via delegation below.
            return '<div class="appt-tray-row" data-sale-id="' + escapeHtml(s.id) + '" style="display:grid;grid-template-columns:1fr auto auto auto;gap:14px;align-items:center;padding:10px 12px;background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin:4px 0">'
                 + '<div class="appt-tray-resume" style="cursor:pointer">'
                 + '<div style="font-weight:500;font-size:13px">' + escapeHtml(s.customer_name) + (s.ra_number ? ' — Appt ' + escapeHtml(s.ra_number) : '') + '</div>'
                 + '<div style="font-size:11px;color:var(--ia-text-dim);margin-top:2px">' + escapeHtml(s.sale_number) + ' · ' + s.item_count + ' line' + (s.item_count === 1 ? '' : 's') + '</div>'
                 + '</div>'
                 + '<div style="font-weight:500;font-size:14px">' + escapeHtml(s.total_display) + '</div>'
                 + '<button type="button" class="ia-btn ia-btn--primary ia-btn--sm appt-tray-pay">Take payment →</button>'
                 + '<button type="button" class="appt-tray-dismiss" aria-label="Remove from list" title="Remove from list" style="background:none;border:none;color:var(--ia-text-dim);font-size:18px;line-height:1;cursor:pointer;padding:4px 8px">×</button>'
                 + '</div>';
          }).join('');
          loaded = true;
          wireTrayRowActions();
        });
    }
    open = !open;
    listEl.style.display = open ? 'block' : 'none';
    toggle.textContent = open ? 'Hide list' : 'View list';
  });

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // MARKER-PATCH-180 — wire resume/pay/dismiss on tray rows.
  function wireTrayRowActions() {
    listEl.querySelectorAll('.appt-tray-row').forEach(function (row) {
      var saleId = row.getAttribute('data-sale-id');
      var resume = function () { window.location.href = '?resume=' + saleId; };
      var body = row.querySelector('.appt-tray-resume');
      var pay  = row.querySelector('.appt-tray-pay');
      if (body) body.addEventListener('click', resume);
      if (pay)  pay.addEventListener('click', function (e) { e.stopPropagation(); resume(); });
      var dismiss = row.querySelector('.appt-tray-dismiss');
      if (dismiss) dismiss.addEventListener('click', async function (e) {
        e.stopPropagation();
        dismiss.disabled = true;
        try {
          var res = await fetch('{{ route("tenant.register.appointment-tray.dismiss", ["subdomain" => tenant()->subdomain]) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ sale_id: saleId }),
            credentials: 'same-origin',
          });
          var data = await res.json();
          if (!data.ok) { dismiss.disabled = false; if (window.IntakeToast) IntakeToast.error(data.error || 'Could not remove.'); return; }
          row.style.transition = 'opacity .2s ease';
          row.style.opacity = '0';
          setTimeout(function () {
            row.remove();
            // Decrement the banner count; hide the whole banner if empty.
            var countEl = document.querySelector('#appointment-tray-banner div[style*="font-weight:500"]');
            var banner = document.getElementById('appointment-tray-banner');
            if (!listEl.querySelector('.appt-tray-row')) {
              if (banner) banner.style.display = 'none';
              listEl.style.display = 'none';
            } else if (countEl) {
              var n = (listEl.querySelectorAll('.appt-tray-row').length);
              countEl.textContent = n + (n === 1 ? ' appointment is' : ' appointments are') + ' ready for checkout';
            }
          }, 210);
        } catch (err) {
          dismiss.disabled = false;
          if (window.IntakeToast) IntakeToast.error('Network error.');
        }
      });
    });
  }
})();
</script>

@if(($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true))
{{-- MARKER-PATCH-170 — Stripe.js for Direct Payments hand-keyed flow --}}
<script src="https://js.stripe.com/v3/"></script>
{{-- MARKER-PATCH-172 — QR code library for send-payment-link --}}
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
@endif
@endpush

REFQTY_4_EOF

echo "refund-quantities-dispositions applied — server: git pull && php artisan migrate --force && php artisan view:clear"
