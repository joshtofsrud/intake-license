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
            // Auto-suggest a source location: any OTHER location with positive stock.
            $fromLocationId = $data['from_location_id'] ?? null;
            if (!$fromLocationId) {
                $candidate = TenantInventoryItemLocation::where('inventory_item_id', $data['inventory_item_id'])
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

    public function markFulfilled(string $id, ?string $byUserId = null): TenantTransferRequest
    {
        return DB::transaction(function () use ($id, $byUserId) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status !== TenantTransferRequest::STATUS_PENDING) {
                throw new InvalidArgumentException("Transfer request is not pending (status={$tr->status}).");
            }
            $tr->status = TenantTransferRequest::STATUS_FULFILLED;
            $tr->fulfilled_at = now();
            $tr->fulfilled_by_user_id = $byUserId;
            $tr->save();
            return $tr;
        });
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
}
