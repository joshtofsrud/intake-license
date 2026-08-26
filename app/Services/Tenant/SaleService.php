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
                'po_number'          => $data['po_number'] ?? null, // MARKER-BIZ-PO
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
                // MARKER-SALE-DISCOUNT — whole-sale discount. recalculate()
                // clamps it to the subtotal once the lines exist.
                'sale_discount_cents'    => max(0, (int) ($data['sale_discount_cents'] ?? 0)),
                'discount_redemption_id' => $data['discount_redemption_id'] ?? null,
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

            // MARKER-GIFTCARDS -- inside the sale transaction, BEFORE the
            // fail-open payment-ledger block: debit any gift-card tenders
            // (throws on unknown code / short balance, rolling everything
            // back) and activate any gift cards sold on this sale. Paid
            // sales only -- an unpaid sale must never activate a card.
            if (($finalSale->payment_status ?? null) === 'paid') {
                $gifts = app(\App\Services\Tenant\GiftCardService::class);
                $gifts->redeemTenders($finalSale, $data);
                $gifts->issueForSale($finalSale);
            }

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
            // MARKER-SO-SALE-LINK — discarding a draft or quote retracts the
            // special orders it requested. Orders already placed with a
            // vendor are left alone: goods may be inbound. Same rule as
            // removing a part from an appointment.
            // MARKER-DISCARD-TENANT-SCOPE — was $tenantId, which is method
            // scope and was never imported into this closure; the row itself
            // carries the tenant, so read it from there.
            $orphans = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $sale->tenant_id)
                ->where('sale_id', $sale->id)
                ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
                ->pluck('id');
            foreach ($orphans as $soId) {
                try {
                    app(\App\Services\Tenant\SpecialOrderService::class)
                        ->cancel($soId, 'Draft discarded before checkout.');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('special_order.discard_cancel_failed', [
                        'special_order_id' => $soId, 'error' => $e->getMessage(),
                    ]);
                }
            }

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

            // MARKER-GIFTCARDS -- same as createSale, but only when this
            // commit is actually taking payment (quotes/drafts committing to
            // unpaid states neither debit nor activate).
            if ($newPaymentStatus === 'paid') {
                $gifts = app(\App\Services\Tenant\GiftCardService::class);
                $gifts->redeemTenders($finalSale, $data);
                $gifts->issueForSale($finalSale);
            }

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

        // MARKER-GIFTCARDS -- gift lines carry their gift details in metadata
        // (survives drafts/quotes; commitDraft activates from the row). Tax
        // is charged when the card is SPENT, never when it is sold.
        $metadata = null;
        if ($type === 'gift_card') {
            $isTaxable = false;
            $gc = (array) ($data['gift_card'] ?? []);
            $metadata = [
                'kind'            => ($gc['kind'] ?? 'physical') === 'egift' ? 'egift' : 'physical',
                'code'            => isset($gc['code']) ? \App\Models\Tenant\TenantGiftCard::normalizeCode((string) $gc['code']) : null,
                'recipient_name'  => $gc['recipient_name'] ?? null,
                'recipient_email' => $gc['recipient_email'] ?? null,
                'gift_message'    => $gc['gift_message'] ?? null,
            ];
        }

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
            'metadata'            => $metadata ?? null, // MARKER-GIFTCARDS
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

        // MARKER-BIZ-TAX — a tax-exempt customer pays no line tax, and the
        // certificate is snapshotted onto the sale so a later edit to the
        // customer cannot rewrite what was true when it was rung up.
        // tax_locked sales are deliberately untouched: they carry
        // pre-computed tax from appointments and must be preserved.
        $exemptCustomer = null;
        if (! $taxLocked && $sale->customer_id) {
            $exemptCustomer = \App\Models\Tenant\TenantCustomer::where('tenant_id', $sale->tenant_id)
                ->where('id', $sale->customer_id)
                ->where('tax_exempt', true)
                ->first(['id', 'tax_exempt_certificate']);
        }
        $isExempt = $exemptCustomer !== null;

        $subtotal = 0;
        $discount = 0;
        $tax      = 0;

        // MARKER-SALE-DISCOUNT — a whole-sale discount reduces the taxable
        // base, so it has to be spread over the lines before tax is figured.
        // Allocation is proportional to line_total with largest-remainder for
        // the odd cents, so the parts always sum to exactly the discount.
        $saleDiscount = max(0, (int) ($sale->sale_discount_cents ?? 0));
        $lineGross    = [];
        foreach ($sale->items as $item) {
            $lineGross[$item->id] = (int) $item->line_total_cents;
        }
        $grossTotal = array_sum($lineGross);

        // Never discount below zero: clamp to what the sale is actually worth.
        if ($saleDiscount > $grossTotal) {
            $saleDiscount = $grossTotal;
        }

        $allocated = [];
        if ($saleDiscount > 0 && $grossTotal > 0) {
            $remainders = [];
            $running    = 0;
            foreach ($lineGross as $id => $gross) {
                $exact          = $gross * $saleDiscount / $grossTotal;
                $floor          = (int) floor($exact);
                $allocated[$id] = $floor;
                $remainders[$id]= $exact - $floor;
                $running       += $floor;
            }
            // Hand the leftover cents to the largest remainders first.
            arsort($remainders);
            $leftover = $saleDiscount - $running;
            foreach (array_keys($remainders) as $id) {
                if ($leftover <= 0) break;
                $allocated[$id]++;
                $leftover--;
            }
        }

        foreach ($sale->items as $item) {
            $subtotal += $item->line_total_cents;
            $discount += $item->discount_cents;
            $lineShare = (int) ($allocated[$item->id] ?? 0);
            $taxableBase = max(0, (int) $item->line_total_cents - $lineShare);

            // tax_locked sales (e.g. bridge-created from appointments) carry
            // pre-computed per-line tax that we must preserve, not recompute.
            if ($taxLocked) {
                $tax += (int) $item->tax_cents;
                continue;
            }

            $shouldTax = ! $isExempt // MARKER-BIZ-TAX
                && $item->is_taxable
                && ($item->type !== 'service' || $taxServicesByDefault);

            if ($shouldTax && $taxRate > 0) {
                // MARKER-SALE-DISCOUNT — tax the discounted base, not the gross.
                $lineTax = (int) round($taxableBase * ($taxRate / 100));
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

        // MARKER-SALE-DISCOUNT — the whole-sale discount comes off the total.
        // subtotal_cents stays the gross of the lines, so a receipt can show
        // subtotal, then the discount, then tax.
        $total = max(0, $subtotal - $saleDiscount) + $tax + $sale->tip_cents + $sale->surcharge_cents;

        $saleUpdate = [
            'subtotal_cents'      => $subtotal,
            'discount_cents'      => $discount,
            'sale_discount_cents' => $saleDiscount, // clamped value wins
            'tax_cents'           => $tax,
            'total_cents'         => $total,
        ];

        // MARKER-BIZ-TAX — audit stamp. Only written when this pass actually
        // decided the tax (tax_locked sales keep whatever they carried).
        if (! $taxLocked) {
            $saleUpdate['tax_exempt_applied']     = $isExempt;
            $saleUpdate['tax_exempt_certificate'] = $isExempt
                ? $exemptCustomer->tax_exempt_certificate
                : null;
        }

        $sale->update($saleUpdate);

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
                // MARKER-SALE-DISCOUNT — set below, once the refunded lines
                // are known and the share of the sale discount can be figured.
                'sale_discount_cents' => 0,
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

            // MARKER-SALE-DISCOUNT — a refund gives back the same proportion of
            // the whole-sale discount as of the value being returned, so a
            // partial refund can't hand back more than was actually paid.
            $origSaleDiscount = (int) ($original->sale_discount_cents ?? 0);
            if ($origSaleDiscount > 0) {
                $origGross = max(1, (int) $original->subtotal_cents);
                $backGross = 0;
                foreach ($refund->fresh('items')->items as $rl) {
                    $backGross += (int) $rl->line_total_cents;
                }
                $refund->update([
                    'sale_discount_cents' => min(
                        $origSaleDiscount,
                        (int) round($origSaleDiscount * ($backGross / $origGross))
                    ),
                ]);
            }

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
                'po_number'          => $data['po_number'] ?? null, // MARKER-BIZ-PO
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
