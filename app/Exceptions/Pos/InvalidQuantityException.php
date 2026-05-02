<?php

namespace App\Exceptions\Pos;

use Exception;

/**
 * Thrown when a quantity argument is zero, negative, or otherwise invalid
 * for the operation. Decrements expect positive quantities (the negation
 * happens inside the service); adjustments expect non-negative absolute counts.
 */
class InvalidQuantityException extends Exception
{
}
