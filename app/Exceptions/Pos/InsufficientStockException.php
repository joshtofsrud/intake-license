<?php

namespace App\Exceptions\Pos;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use Exception;

/**
 * Thrown when a stock decrement would go negative AND allow_oversell=false.
 *
 * Caught by the register flow to surface "Item out of stock" to the cashier
 * with the option to override (if the cashier has permission) or substitute.
 */
class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly TenantInventoryItem $item,
        public readonly TenantLocation $location,
        public readonly int $currentStock,
        public readonly int $requestedQuantity,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock: %s at %s has %d on hand, requested %d.',
            $item->name,
            $location->name,
            $currentStock,
            $requestedQuantity,
        ));
    }
}
