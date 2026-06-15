<?php
// MARKER-PATCH-HLC1

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a distributor API call fails. Carries enough context for the
 * sync layer to record last_sync_error and for the test command to surface
 * a useful message.
 */
class DistributorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $distributorCode = '',
        public readonly ?int $status = null,
        public readonly ?string $endpoint = null,
    ) {
        parent::__construct($message);
    }
}
