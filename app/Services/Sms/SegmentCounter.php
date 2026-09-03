<?php

namespace App\Services\Sms;

/**
 * MARKER-SMS-METER — how many segments a message will actually cost.
 *
 * GSM-7 fits 160 characters in one segment, 153 per part once a message is
 * split (the header eats the difference). A single character outside GSM-7 —
 * an emoji, a curly quote pasted from a document — forces the whole message
 * into UCS-2, where the limits drop to 70 and 67. That cliff is why a message
 * a shop thinks is short can cost three segments.
 *
 * A handful of GSM characters (^ { } [ ] ~ | € and backslash) occupy two
 * positions rather than one; they are counted as two.
 */
class SegmentCounter
{
    /** The GSM 03.38 basic alphabet. */
    private const GSM_BASIC =
        "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** GSM characters that take two positions. */
    private const GSM_EXTENDED = "^{}\\[~]|€";

    public static function isGsm(string $text): bool
    {
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (mb_strpos(self::GSM_BASIC, $ch) === false && mb_strpos(self::GSM_EXTENDED, $ch) === false) {
                return false;
            }
        }
        return true;
    }

    /** Characters as the carrier counts them, extended GSM chars counting twice. */
    public static function units(string $text): int
    {
        if (! self::isGsm($text)) {
            return mb_strlen($text, 'UTF-8');
        }

        $units = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $units += mb_strpos(self::GSM_EXTENDED, $ch) !== false ? 2 : 1;
        }
        return $units;
    }

    public static function segments(string $text): int
    {
        $text = (string) $text;
        if ($text === '') {
            return 1; // an empty send is still a message the carrier bills
        }

        $gsm   = self::isGsm($text);
        $units = self::units($text);

        $single = $gsm ? 160 : 70;
        $multi  = $gsm ? 153 : 67;

        if ($units <= $single) {
            return 1;
        }
        return (int) ceil($units / $multi);
    }

    /** For the UI and the log: why it costs what it costs. */
    public static function explain(string $text): array
    {
        return [
            'encoding' => self::isGsm($text) ? 'GSM-7' : 'Unicode',
            'units'    => self::units($text),
            'segments' => self::segments($text),
        ];
    }
}
