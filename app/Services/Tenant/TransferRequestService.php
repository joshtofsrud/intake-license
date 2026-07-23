<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantTransferRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * patch-100a: lightweight service for creating + closing
 * transfer requests from the register and (later) admin UI.
 */
class TransferRequestService
{
    /**
     * Create a pending transfer request.
     *
     * Required: tenant_id, inventory_item_id, to_location_id, quantity.
     * Optional: from_location_id (auto-suggested if omitted),
     *           requested_by_user_id, sale_id, notes.
     */
    public function create(array $data): TenantTransferRequest
    {
        foreach (['tenant_id', 'inventory_item_id', 'to_location_id', 'quantity'] as $req) {
            if (empty($data[$req])) {
                throw new InvalidArgumentException("{$req} is required.");
            }
        }

        return DB::transaction(function () use ($data) {
            // MARKER-TRANSFER-SCOPE — every id crossing a request boundary
            // proves ownership before anything reads or writes through it.
            // Previously only presence was checked, so a foreign item id
            // could seed a transfer whose source auto-suggest then selected
            // ANOTHER TENANT'S stock location.
            $tenantId = (string) $data['tenant_id'];
            self::assertOwned(TenantInventoryItem::class, $data['inventory_item_id'], $tenantId, 'inventory item');
            self::assertOwned(\App\Models\Tenant\TenantLocation::class, $data['to_location_id'], $tenantId, 'destination location');
            if (!empty($data['from_location_id'])) {
                self::assertOwned(\App\Models\Tenant\TenantLocation::class, $data['from_location_id'], $tenantId, 'source location');
            }
            if (!empty($data['sale_id'])) {
                self::assertOwned(\App\Models\Tenant\TenantSale::class, $data['sale_id'], $tenantId, 'sale');
            }

            // Auto-suggest a source location: any OTHER location with positive stock.
            $fromLocationId = $data['from_location_id'] ?? null;
            if (!$fromLocationId) {
                $candidate = TenantInventoryItemLocation::where('tenant_id', $tenantId) // MARKER-TRANSFER-SCOPE
                    ->where('inventory_item_id', $data['inventory_item_id'])
                    ->where('location_id', '!=', $data['to_location_id'])
                    ->where('computed_stock_count', '>', 0)
                    ->orderByDesc('computed_stock_count')
                    ->first();
                $fromLocationId = $candidate?->location_id;
            }

            return TenantTransferRequest::create([
                'id'                   => (string) Str::uuid(),
                'tenant_id'            => $data['tenant_id'],
                'inventory_item_id'    => $data['inventory_item_id'],
                'to_location_id'       => $data['to_location_id'],
                'from_location_id'     => $fromLocationId,
                'quantity'             => max(1, (int) $data['quantity']),
                'requested_by_user_id' => $data['requested_by_user_id'] ?? null,
                'sale_id'              => $data['sale_id'] ?? null,
                'status'               => TenantTransferRequest::STATUS_PENDING,
                'notes'                => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * patch-102 markSent — source location sends the items.
     * Decrements source stock, sets status=in_transit, records
     * quantity_sent (may be partial), sent_at, sent_by.
     */
    public function markSent(string $id, int $quantitySent, ?string $byUserId = null): TenantTransferRequest
    {
        return DB::transaction(function () use ($id, $quantitySent, $byUserId) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status !== TenantTransferRequest::STATUS_PENDING) {
                throw new InvalidArgumentException("Transfer request is not pending (status={$tr->status}).");
            }
            if ($quantitySent < 1) {
                throw new InvalidArgumentException('Quantity sent must be at least 1.');
            }
            if (!$tr->from_location_id) {
                throw new InvalidArgumentException('Transfer request has no source location set.');
            }

            // MARKER-TRANSFER-SCOPE — the tenant comes from the transfer row
            // itself, never inferred from a related record, and both the item
            // and the source location must belong to it. Defense in depth: a
            // row poisoned before this fix can no longer move foreign stock.
            $tr->load('inventoryItem', 'fromLocation');
            $item = $tr->inventoryItem;
            $fromLoc = $tr->fromLocation;

            if (!$item || !$fromLoc) {
                throw new InvalidArgumentException('Item or source location missing.');
            }
            self::assertRowTenant($item->tenant_id, $tr->tenant_id, 'inventory item');
            self::assertRowTenant($fromLoc->tenant_id, $tr->tenant_id, 'source location');

            $tenant = \App\Models\Tenant::find($tr->tenant_id);
            if (!$tenant) {
                throw new InvalidArgumentException('Transfer request tenant not found.');
            }

            // Decrement source stock — uses the Pos InventoryService primitive,
            // which writes a movement row with referenceType='transfer_request'.
            app(\App\Services\Pos\InventoryService::class)->decrementStock(
                tenant: $tenant,
                item: $item,
                location: $fromLoc,
                quantity: $quantitySent,
                referenceType: 'transfer_request',
                referenceId: $tr->id,
                tenantUser: $byUserId ? \App\Models\Tenant\TenantUser::find($byUserId) : null,
                reason: 'Transfer out',
                notes: "To {$tr->toLocation?->name}",
            );

            $tr->status = TenantTransferRequest::STATUS_IN_TRANSIT;
            $tr->quantity_sent = $quantitySent;
            $tr->sent_at = now();
            $tr->sent_by_user_id = $byUserId;
            $tr->save();
            return $tr;
        });
    }

    /**
     * patch-102 markReceived — destination location receives the items.
     * Increments destination stock, sets status=fulfilled.
     * Reuses fulfilled_at + fulfilled_by_user_id columns for "received".
     */
    public function markReceived(string $id, ?string $byUserId = null): TenantTransferRequest
    {
        return DB::transaction(function () use ($id, $byUserId) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status !== TenantTransferRequest::STATUS_IN_TRANSIT) {
                throw new InvalidArgumentException("Transfer request is not in transit (status={$tr->status}).");
            }

            // MARKER-TRANSFER-SCOPE — see markSent.
            $tr->load('inventoryItem', 'toLocation');
            $item = $tr->inventoryItem;
            $toLoc = $tr->toLocation;

            if (!$item || !$toLoc) {
                throw new InvalidArgumentException('Item or destination location missing.');
            }
            self::assertRowTenant($item->tenant_id, $tr->tenant_id, 'inventory item');
            self::assertRowTenant($toLoc->tenant_id, $tr->tenant_id, 'destination location');

            $tenant = \App\Models\Tenant::find($tr->tenant_id);
            if (!$tenant) {
                throw new InvalidArgumentException('Transfer request tenant not found.');
            }

            $qty = (int) ($tr->quantity_sent ?? $tr->quantity);

            app(\App\Services\Pos\InventoryService::class)->incrementStock(
                tenant: $tenant,
                item: $item,
                location: $toLoc,
                quantity: $qty,
                referenceType: 'transfer_request',
                referenceId: $tr->id,
                tenantUser: $byUserId ? \App\Models\Tenant\TenantUser::find($byUserId) : null,
                reason: 'Transfer in',
                notes: "From {$tr->fromLocation?->name}",
            );

            $tr->status = TenantTransferRequest::STATUS_FULFILLED;
            $tr->fulfilled_at = now();
            $tr->fulfilled_by_user_id = $byUserId;
            $tr->save();
            return $tr;
        });
    }

    /**
     * Legacy alias retained so 100B controllers calling markFulfilled
     * still work — they'll only succeed if status is in_transit.
     */
    public function markFulfilled(string $id, ?string $byUserId = null): TenantTransferRequest
    {
        return $this->markReceived($id, $byUserId);
    }

    public function cancel(string $id): TenantTransferRequest
    {
        return DB::transaction(function () use ($id) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status === TenantTransferRequest::STATUS_FULFILLED) {
                throw new InvalidArgumentException('Already fulfilled, cannot cancel.');
            }
            $tr->status = TenantTransferRequest::STATUS_CANCELLED;
            $tr->save();
            return $tr;
        });
    }

    /**
     * MARKER-TRANSFER-SCOPE — assert a record exists AND belongs to the given
     * tenant. Existence alone is not authorization: unguessable ids leak
     * through shared browsers, screenshots, and support threads.
     */
    protected static function assertOwned(string $modelClass, string $id, string $tenantId, string $label): void
    {
        $owns = $modelClass::where('id', $id)->where('tenant_id', $tenantId)->exists();
        if (! $owns) {
            throw new InvalidArgumentException("That {$label} does not belong to this tenant.");
        }
    }

    /** MARKER-TRANSFER-SCOPE — compare a loaded row's tenant against the expected one. */
    protected static function assertRowTenant(?string $rowTenantId, ?string $expectedTenantId, string $label): void
    {
        if (! $rowTenantId || ! $expectedTenantId || $rowTenantId !== $expectedTenantId) {
            throw new InvalidArgumentException("Transfer {$label} belongs to a different tenant — refusing to move stock.");
        }
    }
}
