<?php

namespace App\Services\Tenant;

use Exception;

/**
 * Thrown by SpecialOrderService when:
 *   - A state transition is attempted from an illegal source state
 *     (e.g. trying to markArrived a 'needed' SO)
 *   - Required fields for a transition are missing (e.g. marking
 *     ordered without a vendor_id)
 *   - An attempted partial-receipt split has an invalid quantity
 *     (zero, negative, or >= the row's total quantity)
 *
 * Mirrors the SaleValidationException pattern. Controllers catch this
 * and surface as 422 with the exception's message.
 */
class SpecialOrderValidationException extends Exception
{
}
