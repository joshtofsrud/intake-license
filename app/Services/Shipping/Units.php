<?php

namespace App\Services\Shipping;

/**
 * MARKER-SHIPPING-RATES: unit handling with no database. Everything the shipping code
 * compares is in ounces and inches; a distributor's own unit (set by master admin,
 * per distributor) is converted once, here.
 */
class Units
{
    private const OZ = ['oz' => 1.0, 'lb' => 16.0, 'g' => 0.03527396, 'kg' => 35.27396];
    private const IN = ['in' => 1.0, 'ft' => 12.0, 'mm' => 0.03937008, 'cm' => 0.3937008];

    public static function weightUnits(): array { return array_keys(self::OZ); }
    public static function lengthUnits(): array { return array_keys(self::IN); }

    public static function toOunces($value, string $unit): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) return null;
        return round((float) $value * (self::OZ[strtolower($unit)] ?? 16.0), 2);
    }

    public static function toInches($value, string $unit): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) return null;
        return round((float) $value * (self::IN[strtolower($unit)] ?? 1.0), 2);
    }

    /**
     * A feed's dimensions arrive in several shapes: {Length,Width,Height} (any case, or
     * l/w/h), a list of three, or "10 x 8 x 4". Returns [l, w, h] in inches, or null when
     * all three are not there — a half-known box is worse than an unknown one.
     */
    public static function dimensions($raw, string $unit = 'in'): ?array
    {
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $raw = $j;
            } elseif (preg_match_all('/\d+(?:\.\d+)?/', $raw, $m) && count($m[0]) === 3) {
                $raw = $m[0];
            } else {
                return null;
            }
        }
        if (! is_array($raw)) return null;

        $vals = [];
        if (array_is_list($raw)) {
            $vals = array_slice($raw, 0, 3);
        } else {
            $low = array_change_key_case($raw, CASE_LOWER);
            foreach ([['length', 'l'], ['width', 'w'], ['height', 'h']] as [$long, $short]) {
                $vals[] = $low[$long] ?? $low[$short] ?? null;
            }
        }
        if (count($vals) !== 3) return null;
        $out = array_map(fn ($v) => self::toInches($v, $unit), $vals);
        return in_array(null, $out, true) ? null : $out;
    }
}
