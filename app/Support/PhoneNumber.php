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

        // MARKER-PHONE-EXT — cut the extension off FIRST. An imported business
        // list is full of "509-555-1234 ext 2", and folding those digits into
        // the number produced a real-looking wrong one (+50955512342).
        $trimmed = preg_replace('/\s*(?:,|;|extension|ext\.?|x)\s*\d+\s*$/i', '', trim($raw));
        $trimmed = $trimmed !== null && $trimmed !== '' ? $trimmed : trim($raw);

        $digits = preg_replace('/\D+/', '', $trimmed);
        if (! $digits) return null;

        if (strlen($digits) === 10) return '+1' . $digits;                                  // US 10-digit
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return '+' . $digits;  // US w/ country code

        // MARKER-PHONE-EXT — an explicit + means the person told us it is
        // international, so trust it within E.164's own length limits.
        if (str_starts_with($trimmed, '+')) {
            return (strlen($digits) >= 8 && strlen($digits) <= 15) ? '+' . $digits : null;
        }

        // Anything else is not a number we can dial. Previously this returned
        // '+' . $digits regardless, which meant 7-digit fragments and
        // 11-digit-non-US strings were stored as if they were valid.
        return null;
    }
}
