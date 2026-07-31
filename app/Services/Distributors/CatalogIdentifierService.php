<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;

/**
 * Turns a catalog row into the set of identifiers it can be matched on.
 *
 * The point of the set: a single coalesced key only matches when both sides
 * pick the same rung, and they often don't. HLC frequently has an EAN and no
 * UPC; BTI has a UPC and no EAN; both have brand+MPN. Emitting every
 * identifier a row carries lets any one of them do the matching.
 */
class CatalogIdentifierService
{
    /**
     * MARKER-MPN-FLOOR — floors differ by type, on purpose.
     *
     * A barcode shorter than 4 digits is corrupt data. A part number shorter
     * than 4 is routine — "BR-3" is a real Wheels Manufacturing SKU — and it
     * is already brand-qualified in the key, so WHEELSMANUFACTURING|BR3
     * cannot collide with another manufacturer's BR3. The original single
     * floor of 4 was guarding against a collision the brand prefix had
     * already ruled out, and it silently dropped valid rows.
     *
     * Single characters still stay out: within one brand they are far more
     * likely to be a data-entry artefact than a distinct part.
     */
    private const MIN_LENGTH = 4;        // barcodes
    private const MIN_MPN_LENGTH = 2;    // brand-qualified part numbers

    /**
     * Values that appear across unrelated products. Matching on one of these
     * would merge things that have nothing to do with each other, which is
     * worse than not matching at all.
     */
    private const JUNK = [
        'NA', 'N/A', 'NONE', 'NULL', 'NOMPN', 'UNKNOWN', 'TBD',
        '0', '00', '000', '0000', '00000', '000000',
        '9999', '99999', '999999', 'XXXX', 'XXXXX',
    ];

    /**
     * @return array<int,array{type:string,value:string}>
     */
    public function forRow(PlatformDistributorCatalog $row): array
    {
        $out = [];

        foreach ($this->barcodes((string) $row->upc) as $v) {
            $out[] = ['type' => 'upc', 'value' => $v];
        }

        foreach ($this->barcodes((string) $row->ean) as $v) {
            $out[] = ['type' => 'ean', 'value' => $v];
        }

        $mpn = $this->mpn((string) $row->manufacturer, (string) $row->manufacturer_sku);
        if ($mpn !== null) {
            $out[] = ['type' => 'mpn', 'value' => $mpn];
        }

        // Same type+value twice on one row would violate the unique key.
        $seen = [];
        return array_values(array_filter($out, function ($i) use (&$seen) {
            $k = $i['type'] . '|' . $i['value'];
            if (isset($seen[$k])) { return false; }
            $seen[$k] = true;
            return true;
        }));
    }

    /**
     * A barcode yields one value, or two when it is a 13-digit number with a
     * leading zero — that form IS a zero-padded UPC-A, so both are stored and
     * a genuinely padded pair still meets. It does NOT make unrelated UPC and
     * EAN numbers equal; nothing can, and nothing here pretends otherwise.
     *
     * @return array<int,string>
     */
    private function barcodes(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || strlen($digits) < self::MIN_LENGTH) {
            return [];
        }
        if ($this->isJunk($digits)) {
            return [];
        }

        $out = [$digits];

        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $out[] = substr($digits, 1);
        } elseif (strlen($digits) === 12) {
            $out[] = '0' . $digits;   // the same product's EAN-13 form
        }

        return array_values(array_unique($out));
    }

    /**
     * Brand-qualified part number. An MPN alone is not unique across
     * manufacturers — plenty of brands ship a part called "100" — so the
     * brand travels with it and a bare MPN is never emitted.
     */
    private function mpn(string $brand, string $sku): ?string
    {
        $b = $this->squash($brand);
        $s = $this->squash($sku);

        if ($b === '' || $s === '') {
            return null;
        }
        if (strlen($s) < self::MIN_MPN_LENGTH || $this->isJunk($s)) {
            return null;
        }

        return $b . '|' . $s;
    }

    /** Upper-case, strip everything that isn't alphanumeric. */
    private function squash(string $v): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim($v)) ?? '');
    }

    private function isJunk(string $v): bool
    {
        return in_array(strtoupper($v), self::JUNK, true);
    }
}
