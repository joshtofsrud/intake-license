<?php

namespace App\Exceptions\Pos;

use Exception;

/**
 * Thrown when an operation is called with an item or location that
 * doesn't belong to the passed tenant.
 *
 * Defense in depth — the service should never be called this way in
 * normal code paths, but throwing makes cross-tenant data leaks
 * impossible by construction.
 */
class TenantMismatchException extends Exception
{
    public function __construct(string $message = 'Operation references resources from a different tenant.')
    {
        parent::__construct($message);
    }
}
