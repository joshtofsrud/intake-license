<?php

namespace App\Support;

/**
 * MARKER-PATCH-392 — One phone-number standard across Intake.
 *
 * Normalizes any human-entered phone string to E.164 (US default) so the same
 * number stored from booking, customer entry, signup, or an inbound text always
 * matches. Single source of truth; SmsService::normalizePhone() delegates here.
 */
class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        if (! $raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (! $digits) return null;

        if (strlen($digits) === 10) return '+1' . $digits;                                  // US 10-digit
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return '+' . $digits;  // US w/ country code
        if (str_starts_with($raw, '+')) return '+' . $digits;                               // already international
        return '+' . $digits;                                                               // best-effort
    }
}
