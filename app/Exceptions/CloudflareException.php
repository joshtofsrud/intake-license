<?php

namespace App\Exceptions;

/**
 * CloudflareException
 *
 * Thrown by CloudflareForSaasService when the Cloudflare API rejects a
 * call. Carries enough structure for callers to react sensibly:
 *
 *   - $code      : machine-readable. Matches CF's error.code when available
 *                  ('unauthorized', 'hostname_taken', 'rate_limited', etc.)
 *                  or our own ('not_configured', 'network').
 *   - $cfErrors  : raw error array from Cloudflare response (for logging).
 *   - $httpStatus: the HTTP status returned (0 if no response).
 *
 * Callers should catch and decide:
 *   - 'unauthorized' / 'not_configured' → ops issue, page someone
 *   - 'hostname_taken' → user-visible message
 *   - 'rate_limited' → retry with backoff
 *   - others → log + show generic error
 */
class CloudflareException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown',
        public readonly array $cfErrors = [],
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
