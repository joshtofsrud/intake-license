<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a MySQL advisory lock cannot be acquired within its timeout.
 *
 * Callers should catch this and translate to the appropriate user-facing
 * response — usually HTTP 409 Conflict with a "slot just taken, try again"
 * message in the booking path.
 */
class LockAcquisitionException extends RuntimeException
{
}
