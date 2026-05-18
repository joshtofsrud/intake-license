<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantSpecialOrderCounter;
use App\Models\Tenant\TenantSpecialOrderNote;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SpecialOrderService — business logic for the SO lifecycle.
 *
 * STATE MACHINE:
 *
 *     needed  ─┐
 *              ├──► ordered ──► arrived ──► pulled
 *     needed  ─┘
 *
 *     any open state ──► cancelled
 *
 * Notes:
 *   - 'needed' can transition directly to 'ordered' (vendor confirmed)
 *     or to 'cancelled' (request killed before any vendor commitment).
 *   - 'pulled' is terminal. Cannot un-pull.
 *   - 'cancelled' is terminal. Use create() to start fresh if you
 *     want to re-order something that was cancelled.
 *
 * PARTIAL RECEIPT MECHANIC:
 *   When markArrived() is called with $receivedQty < quantity, the
 *   service splits the row:
 *     - Original row → status=arrived, quantity=$receivedQty, sets arrived_at
 *     - New sibling row → parent_id=original.id, status=ordered,
 *       quantity=remaining, expected_arrival_date preserved, same
 *       vendor/customer/appointment/etc. New so_number issued.
 *
 *   The original audit history (notes, ordered_at, ordered_at, etc.) stays
 *   with the original row. The sibling has its own short history starting
 *   from its creation.
 *
 * VENDOR LEAD-TIME LEARNING:
 *   When an SO transitions to 'arrived', the pivot row for (item, vendor)
 *   gets last_ordered_at updated. The lead_time_days field on the pivot
 *   is computed on-demand by averaging actual ordered-to-arrived days
 *   for that pair — we don't try to maintain a running average. Cheap
 *   enough at read time; reconsider if a tenant ever has 10k+ SOs per
 *   vendor.
 */
class SpecialOrderService
{
    // ────────────────────────────────────────────────────────
    //  SO numbering (per-tenant counter)
    // ────────────────────────────────────────────────────────

    /**
     * Atomically increment the per-tenant counter and return the next
     * SO number formatted as "SO-{n}" (zero-padded to 4 digits).
     *
     * Wraps in a DB transaction + lockForUpdate so concurrent SO
     * creations from two staff members can't collide.
     */
    public function nextSpecialOrderNumber(string $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $counter = TenantSpecialOrderCounter::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = TenantSpecialOrderCounter::create([
                    'tenant_id'   => $tenantId,
                    'next_number' => 1,
                ]);
            }

            $n = $counter->next_number;
            $counter->next_number = $n + 1;
            $counter->save();

            return 'SO-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        });
    }

    // ────────────────────────────────────────────────────────
    //  Creation
    // ────────────────────────────────────────────────────────

    /**
     * Create a new SO row. Validates required fields per the chosen
     * initial status, assigns a SO number, snapshots the item name,
     * and writes a "Created" system note.
     *
     * Expected $data keys:
     *   tenant_id            (required)
     *   inventory_item_id    (nullable - "not yet catalogued")
     *   item_name_snapshot   (required - free text fallback when no item)
     *   quantity             (required, > 0)
     *   customer_id          (nullable)
     *   appointment_id       (nullable)
     *   vendor_id            (required if status='ordered'; nullable if 'needed')
     *   po_number            (nullable - usually set at markOrdered)
     *   vendor_reference     (nullable)
     *   status               (default 'needed')
     *   created_from         (default 'manual')
     *   unit_cost_cents_estimated (nullable)
     *   expected_arrival_date (nullable)
     *   deposit_cents        (nullable, default 0)
     *   deposit_paid_at      (nullable)
     *   deposit_payment_ref  (nullable)
     *   batch_id             (nullable)
     *   parent_id            (nullable - set by splitForPartialReceipt only)
     *   created_by_user_id   (nullable)
     *   notes                (nullable - first user note to seed the thread)
     */
    public function create(array $data): TenantSpecialOrder
    {
        if (empty($data['tenant_id'])) {
            throw new SpecialOrderValidationException('tenant_id is required.');
        }
        if (empty($data['item_name_snapshot'])) {
            throw new SpecialOrderValidationException('item_name_snapshot is required.');
        }
        if (!isset($data['quantity']) || (int) $data['quantity'] < 1) {
            throw new SpecialOrderValidationException('quantity must be at least 1.');
        }

        $status = $data['status'] ?? TenantSpecialOrder::STATUS_NEEDED;
        if (!in_array($status, [
            TenantSpecialOrder::STATUS_NEEDED,
            TenantSpecialOrder::STATUS_ORDERED,
        ], true)) {
            throw new SpecialOrderValidationException(
                "Initial status must be 'needed' or 'ordered', got '{$status}'."
            );
        }

        // If creating directly as 'ordered', vendor + ETA + PO are
        // expected. Soft-create with status=needed if those are missing
        // and let the caller transition explicitly via markOrdered().
        if ($status === TenantSpecialOrder::STATUS_ORDERED) {
            if (empty($data['vendor_id'])) {
                throw new SpecialOrderValidationException(
                    "Cannot create with status='ordered' without a vendor_id."
                );
            }
        }

        return DB::transaction(function () use ($data, $status) {
            $soNumber = $this->nextSpecialOrderNumber($data['tenant_id']);

            $so = TenantSpecialOrder::create([
                'tenant_id'                 => $data['tenant_id'],
                'so_number'                 => $soNumber,
                'inventory_item_id'         => $data['inventory_item_id'] ?? null,
                'item_name_snapshot'        => $data['item_name_snapshot'],
                'quantity'                  => (int) $data['quantity'],
                'customer_id'               => $data['customer_id'] ?? null,
                'appointment_id'            => $data['appointment_id'] ?? null,
                'vendor_id'                 => $data['vendor_id'] ?? null,
                'po_number'                 => $data['po_number'] ?? null,
                'vendor_reference'          => $data['vendor_reference'] ?? null,
                'status'                    => $status,
                'created_from'              => $data['created_from'] ?? 'manual',
                'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? null,
                'expected_arrival_date'     => $data['expected_arrival_date'] ?? null,
                'ordered_at'                => $status === TenantSpecialOrder::STATUS_ORDERED ? now() : null,
                'deposit_cents'             => $data['deposit_cents'] ?? 0,
                'deposit_paid_at'           => $data['deposit_paid_at'] ?? null,
                'deposit_payment_ref'       => $data['deposit_payment_ref'] ?? null,
                'batch_id'                  => $data['batch_id'] ?? null,
                'parent_id'                 => $data['parent_id'] ?? null,
                'created_by_user_id'        => $data['created_by_user_id'] ?? null,
            ]);

            // System note recording creation. Includes the creation context
            // so audit history is honest about where this came from.
            $createdFromHuman = match ($so->created_from) {
                'register'    => 'register sale',
                'appointment' => 'work order',
                'item'        => 'item detail page',
                'booking'     => 'customer booking flow',
                default       => 'manual entry',
            };
            $this->writeSystemNote($so, "Created from {$createdFromHuman}.");

            // If the caller provided a seed note, persist it as a user note.
            if (!empty($data['notes'])) {
                $this->addNote($so->id, $data['created_by_user_id'] ?? null, $data['notes']);
            }

            return $so->fresh();
        });
    }

    // ────────────────────────────────────────────────────────
    //  Transitions
    // ────────────────────────────────────────────────────────

    /**
     * needed → ordered. Requires vendor_id, po_number, and an
     * expected_arrival_date. Estimated cost optional but recommended.
     *
     * $data keys consumed:
     *   vendor_id (required)
     *   po_number (required)
     *   vendor_reference (optional - their PO ack number)
     *   expected_arrival_date (required, date string)
     *   unit_cost_cents_estimated (optional)
     */
    public function markOrdered(string $id, array $data): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_ORDERED);

            if (empty($data['vendor_id'])) {
                throw new SpecialOrderValidationException(
                    'vendor_id is required to mark an SO ordered.'
                );
            }
            if (empty($data['po_number'])) {
                throw new SpecialOrderValidationException(
                    'po_number is required to mark an SO ordered.'
                );
            }
            if (empty($data['expected_arrival_date'])) {
                throw new SpecialOrderValidationException(
                    'expected_arrival_date is required to mark an SO ordered.'
                );
            }

            $so->update([
                'status'                    => TenantSpecialOrder::STATUS_ORDERED,
                'vendor_id'                 => $data['vendor_id'],
                'po_number'                 => $data['po_number'],
                'vendor_reference'          => $data['vendor_reference'] ?? $so->vendor_reference,
                'expected_arrival_date'     => $data['expected_arrival_date'],
                'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? $so->unit_cost_cents_estimated,
                'ordered_at'                => now(),
            ]);

            $this->writeSystemNote(
                $so->fresh(),
                "Marked ordered. PO {$data['po_number']} · ETA {$data['expected_arrival_date']}."
            );

            return $so->fresh();
        });
    }

    /**
     * ordered → arrived. Supports partial receipt: when $receivedQty
     * is provided and less than $so->quantity, the row is split via
     * splitForPartialReceipt() before marking arrived.
     *
     * Also writes the vendor invoice fields if provided, and updates
     * the item↔vendor pivot's last_ordered_at.
     *
     * @param int|null $receivedQty       null = full receipt
     * @param int|null $actualUnitCostCents null = use estimated
     * @param string|null $vendorInvoiceNumber  optional, set at receiving
     * @param string|null $vendorInvoiceDate    optional, date string
     */
    public function markArrived(
        string $id,
        ?int $receivedQty = null,
        ?int $actualUnitCostCents = null,
        ?string $vendorInvoiceNumber = null,
        ?string $vendorInvoiceDate = null
    ): TenantSpecialOrder {
        $result = DB::transaction(function () use ($id, $receivedQty, $actualUnitCostCents, $vendorInvoiceNumber, $vendorInvoiceDate) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_ARRIVED);

            $totalQty = (int) $so->quantity;
            $received = $receivedQty ?? $totalQty;

            if ($received < 1) {
                throw new SpecialOrderValidationException(
                    'Received quantity must be at least 1 (cancel the SO instead).'
                );
            }
            if ($received > $totalQty) {
                throw new SpecialOrderValidationException(
                    "Received quantity ({$received}) cannot exceed ordered quantity ({$totalQty})."
                );
            }

            // Partial receipt: split the row before marking arrived.
            if ($received < $totalQty) {
                $this->splitForPartialReceipt($so, $received);
                // After split, $so has quantity=$received. Refresh.
                $so = $so->fresh();
            }

            $so->update([
                'status'                 => TenantSpecialOrder::STATUS_ARRIVED,
                'arrived_at'             => now(),
                'unit_cost_cents_actual' => $actualUnitCostCents ?? $so->unit_cost_cents_actual,
                'vendor_invoice_number'  => $vendorInvoiceNumber ?? $so->vendor_invoice_number,
                'vendor_invoice_date'    => $vendorInvoiceDate ?? $so->vendor_invoice_date,
            ]);

            // Vendor pivot housekeeping — update last_ordered_at for
            // the (item, vendor) pair so future "preferred vendor" reads
            // can recompute lead time fresh.
            $this->updateVendorLeadTime($so->fresh());

            $partialNote = $received < $totalQty
                ? " (partial: {$received} of {$totalQty})"
                : '';
            $this->writeSystemNote($so->fresh(), "Marked arrived{$partialNote}.");

            return $so->fresh();
        });

        // patch-93 dispatch SpecialOrderArrived — fires AFTER the DB
        // transaction commits, so listeners can assume durable state.
        event(new \App\Events\SpecialOrders\SpecialOrderArrived($result));

        return $result;
    }

    /**
     * arrived → pulled. Called when the line is rung up at register
     * or when an appointment containing the SO is completed.
     */
    public function markPulled(string $id): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_PULLED);

            $so->update([
                'status'    => TenantSpecialOrder::STATUS_PULLED,
                'pulled_at' => now(),
            ]);

            $this->writeSystemNote($so->fresh(), 'Marked pulled.');

            return $so->fresh();
        });
    }

    /**
     * Any non-terminal status → cancelled. Records the reason if given.
     * Does NOT refund deposits — that's a controller-layer Stripe call;
     * the service just records that the SO is no longer active.
     */
    public function cancel(string $id, ?string $reason = null): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id, $reason) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_CANCELLED);

            $so->update([
                'status'              => TenantSpecialOrder::STATUS_CANCELLED,
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
            ]);

            $noteBody = $reason ? "Cancelled: {$reason}" : 'Cancelled.';
            $this->writeSystemNote($so->fresh(), $noteBody);

            return $so->fresh();
        });
    }

    // ────────────────────────────────────────────────────────
    //  Partial-receipt row split
    // ────────────────────────────────────────────────────────

    /**
     * Split an ordered SO into two rows because the vendor short-shipped:
     *   - The current row's quantity is reduced to $receivedQty
     *     (caller will then mark it arrived)
     *   - A new sibling row is created with parent_id pointing at the
     *     current row, quantity = remaining, status=ordered
     *
     * The new sibling preserves vendor, customer, appointment, batch,
     * expected_arrival_date, and estimated cost. Its created_from is
     * carried over too. The deposit, however, stays with the parent
     * row — splits don't redistribute money already collected.
     *
     * Returns the new sibling. The parent (current) row mutation
     * happens in place; caller should refresh it.
     */
    public function splitForPartialReceipt(TenantSpecialOrder $so, int $receivedQty): TenantSpecialOrder
    {
        $totalQty = (int) $so->quantity;
        $remaining = $totalQty - $receivedQty;

        if ($receivedQty < 1 || $receivedQty >= $totalQty) {
            throw new SpecialOrderValidationException(
                "Split requires 1 <= receivedQty < quantity (got {$receivedQty} of {$totalQty})."
            );
        }
        if ($so->status !== TenantSpecialOrder::STATUS_ORDERED) {
            throw new SpecialOrderValidationException(
                "Only 'ordered' rows can be partial-receipt split (got status='{$so->status}')."
            );
        }

        return DB::transaction(function () use ($so, $receivedQty, $remaining) {
            // Reduce the parent's quantity in place.
            $so->update(['quantity' => $receivedQty]);

            // Spawn the remainder as a new sibling, parent_id pointing back.
            $remainderNumber = $this->nextSpecialOrderNumber($so->tenant_id);

            $remainderRow = TenantSpecialOrder::create([
                'tenant_id'                 => $so->tenant_id,
                'so_number'                 => $remainderNumber,
                'inventory_item_id'         => $so->inventory_item_id,
                'item_name_snapshot'        => $so->item_name_snapshot,
                'quantity'                  => $remaining,
                'customer_id'               => $so->customer_id,
                'appointment_id'            => $so->appointment_id,
                'vendor_id'                 => $so->vendor_id,
                'po_number'                 => $so->po_number,
                'vendor_reference'          => $so->vendor_reference,
                'status'                    => TenantSpecialOrder::STATUS_ORDERED,
                'created_from'              => $so->created_from,
                'unit_cost_cents_estimated' => $so->unit_cost_cents_estimated,
                'expected_arrival_date'     => $so->expected_arrival_date,
                'ordered_at'                => $so->ordered_at,
                'batch_id'                  => $so->batch_id,
                'parent_id'                 => $so->id,
                'created_by_user_id'        => $so->created_by_user_id,
            ]);

            $this->writeSystemNote(
                $so->fresh(),
                "Partial receipt — split into {$so->so_number} (received {$receivedQty}) "
                . "and {$remainderNumber} (remaining {$remaining})."
            );
            $this->writeSystemNote(
                $remainderRow,
                "Spawned from {$so->so_number} after partial receipt. {$remaining} units remain on order."
            );

            return $remainderRow;
        });
    }

    // ────────────────────────────────────────────────────────
    //  Notes
    // ────────────────────────────────────────────────────────

    /**
     * Append a note to an SO's notes thread. User notes have a
     * tenant_user_id and is_system=false. System notes pass userId=null
     * (or whatever the system "user" is) and isSystem=true.
     */
    public function addNote(
        string $specialOrderId,
        ?string $userId,
        string $body,
        bool $isSystem = false
    ): TenantSpecialOrderNote {
        if (trim($body) === '') {
            throw new SpecialOrderValidationException('Note body cannot be empty.');
        }

        return TenantSpecialOrderNote::create([
            'special_order_id' => $specialOrderId,
            'tenant_user_id'   => $isSystem ? null : $userId,
            'is_system'        => $isSystem,
            'body'             => $body,
        ]);
    }

    // ────────────────────────────────────────────────────────
    //  Internal helpers
    // ────────────────────────────────────────────────────────

    /**
     * Transition rules in one place. Throws on illegal.
     */
    protected function validateTransition(TenantSpecialOrder $so, string $newStatus): void
    {
        $from = $so->status;
        $allowed = match ($newStatus) {
            TenantSpecialOrder::STATUS_ORDERED   => [TenantSpecialOrder::STATUS_NEEDED],
            TenantSpecialOrder::STATUS_ARRIVED   => [TenantSpecialOrder::STATUS_ORDERED],
            TenantSpecialOrder::STATUS_PULLED    => [TenantSpecialOrder::STATUS_ARRIVED],
            TenantSpecialOrder::STATUS_CANCELLED => TenantSpecialOrder::STATUSES_OPEN,
            default => [],
        };

        if (!in_array($from, $allowed, true)) {
            throw new SpecialOrderValidationException(
                "Cannot transition from '{$from}' to '{$newStatus}' on SO {$so->so_number}."
            );
        }
    }

    /**
     * Find an SO by id or throw. Subclassed in tests to scope by
     * tenant; here we just bare findOrFail since controllers will
     * scope before calling.
     */
    protected function findOrFail(string $id): TenantSpecialOrder
    {
        return TenantSpecialOrder::findOrFail($id);
    }

    protected function writeSystemNote(TenantSpecialOrder $so, string $body): void
    {
        $this->addNote($so->id, null, $body, true);
    }

    /**
     * After an SO arrives, touch the (item, vendor) pivot row's
     * last_ordered_at. Doesn't recompute lead_time_days — that's
     * a read-side concern, computed on demand from the SO history.
     *
     * Silent no-op if the SO has no item or no vendor, or no pivot
     * row exists for the pair yet. (The pivot row may legitimately
     * not exist for the first SO from a new vendor — caller can
     * create it explicitly via the item.vendors() relationship.)
     */
    protected function updateVendorLeadTime(TenantSpecialOrder $so): void
    {
        if (!$so->inventory_item_id || !$so->vendor_id) {
            return;
        }

        try {
            $pivot = TenantInventoryItemVendor::where('inventory_item_id', $so->inventory_item_id)
                ->where('vendor_id', $so->vendor_id)
                ->first();

            if ($pivot) {
                $pivot->update(['last_ordered_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Never let pivot housekeeping fail the arrival transition.
            // Log and continue.
            Log::warning('SpecialOrderService::updateVendorLeadTime failed', [
                'so_id'     => $so->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
