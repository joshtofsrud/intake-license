<?php

namespace App\Support;

/**
 * MARKER-LABELS: barcodes for inventory labels, drawn as SVG.
 * A valid 12-digit UPC prints as UPC-A (what every scanner and the register
 * expect on retail goods); anything else — SKU, MPN, a UPC that fails its
 * check digit — prints as Code 128 (set B), which encodes any plain ASCII.
 */
class LabelBarcode
{
    private const C128 = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    private const UPC_L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];

    public static function isUpcA(string $s): bool
    {
        if (! preg_match('/^\d{12}$/', $s)) return false;
        $sum = 0;
        for ($i = 0; $i < 11; $i++) $sum += ((int) $s[$i]) * ($i % 2 === 0 ? 3 : 1);
        return (10 - $sum % 10) % 10 === (int) $s[11];
    }

    /** ['kind' => 'UPC-A'|'Code 128', 'svg' => '<svg …>'] — stretches to its box. */
    public static function svg(string $text): array
    {
        $text = trim($text);
        $rects = '';
        if (self::isUpcA($text)) {
            $bits = '101';
            for ($i = 0; $i < 6; $i++) $bits .= self::UPC_L[(int) $text[$i]];
            $bits .= '01010';
            for ($i = 6; $i < 12; $i++) $bits .= strtr(self::UPC_L[(int) $text[$i]], '01', '10');
            $bits .= '101';
            $x = 9;
            for ($i = 0, $n = strlen($bits); $i < $n; $i++) {
                if ($bits[$i] === '1') $rects .= '<rect x="' . ($x + $i) . '" y="0" width="1" height="100"/>';
            }
            $total = strlen($bits) + 18;
            $kind = 'UPC-A';
        } else {
            $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text);
            $codes = [104]; $sum = 104;
            for ($i = 0, $n = strlen($ascii); $i < $n; $i++) {
                $v = ord($ascii[$i]) - 32;
                $codes[] = $v; $sum += $v * ($i + 1);
            }
            $codes[] = $sum % 103; $codes[] = 106;
            $x = 10; $bar = true;
            foreach ($codes as $c) {
                foreach (str_split(self::C128[$c]) as $w) {
                    if ($bar) $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="100"/>';
                    $x += (int) $w; $bar = ! $bar;
                }
            }
            $total = $x + 10;
            $kind = 'Code 128';
        }
        return ['kind' => $kind, 'svg' => '<svg class="bc" viewBox="0 0 ' . $total . ' 100" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true">' . $rects . '</svg>'];
    }
}
