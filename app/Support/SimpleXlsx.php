<?php

namespace App\Support;

/**
 * Reads the first sheet of an .xlsx as plain rows, for supplier exports that
 * stricter readers refuse. Kona's "Item Valuation Data" export (Acumatica)
 * writes lower-case cell references ("a1"), which OpenSpout rejects outright;
 * this reader takes the column from the reference in any case, and falls back
 * to the cell's position when a reference is missing.
 *
 * Shared strings, inline strings, numbers and booleans are read; formulas use
 * their cached value. Styles and dates are not interpreted.
 */
class SimpleXlsx
{
    /**
     * @return \Generator<int,array<int,string|float|int|null>> rows, each a list indexed from 0
     */
    public static function rows(string $path): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Not a readable .xlsx file.');
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheet  = self::firstSheetPath($zip);
            $xml    = $zip->getFromName($sheet);
            if ($xml === false) {
                throw new \RuntimeException('The workbook has no first sheet.');
            }
        } finally {
            $zip->close();
        }

        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

        $row = null;
        $pos = 0;
        while ($r->read()) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                $row = [];
                $pos = 0;
                if ($r->isEmptyElement) {
                    yield [];
                    $row = null;
                }
                continue;
            }
            if ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'row' && $row !== null) {
                yield self::fill($row);
                $row = null;
                continue;
            }
            if ($row !== null && $r->nodeType === \XMLReader::ELEMENT && $r->localName === 'c') {
                $ref  = (string) $r->getAttribute('r');
                $type = (string) $r->getAttribute('t');
                $col  = $ref !== '' ? self::column($ref) : $pos;
                $pos  = $col + 1;
                $cell = $r->isEmptyElement ? '' : $r->readOuterXml();
                $row[$col] = self::value($cell, $type, $shared);
            }
        }
        $r->close();
    }

    /**
     * The first sheet as records keyed by its header row (trimmed). Rows with
     * no values are skipped.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public static function records(string $path): \Generator
    {
        $header = null;
        foreach (self::rows($path) as $cells) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h), $cells);
                continue;
            }
            $rec = [];
            foreach ($header as $i => $h) {
                if ($h !== '') {
                    $rec[$h] = $cells[$i] ?? null;
                }
            }
            if (array_filter($rec, fn ($v) => $v !== null && $v !== '')) {
                yield $rec;
            }
        }
    }

    private static function fill(array $row): array
    {
        if (! $row) {
            return [];
        }
        $out = [];
        for ($i = 0, $n = max(array_keys($row)); $i <= $n; $i++) {
            $out[$i] = $row[$i] ?? null;
        }
        return $out;
    }

    /** "a1" / "AB12" → 0-based column index. */
    private static function column(string $ref): int
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ref) ?? '');
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private static function value(string $cellXml, string $type, array $shared): mixed
    {
        if ($cellXml === '') {
            return null;
        }
        $x = @simplexml_load_string($cellXml);
        if ($x === false) {
            return null;
        }
        $x->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        if ($type === 'inlineStr') {
            $parts = $x->xpath('.//m:t') ?: $x->xpath('.//t') ?: [];
            return implode('', array_map('strval', $parts));
        }
        $v = isset($x->v) ? (string) $x->v : (($x->xpath('m:v') ?: [])[0] ?? null);
        if ($v === null) {
            return null;
        }
        $v = (string) $v;
        return match ($type) {
            's'         => $shared[(int) $v] ?? '',
            'b'         => $v === '1',
            'str', 'e'  => $v,
            default     => is_numeric($v) ? $v + 0 : $v,
        };
    }

    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($r->read()) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                $si = @simplexml_load_string($r->readOuterXml());
                if ($si === false) {
                    $out[] = '';
                    continue;
                }
                $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $parts = $si->xpath('.//m:t') ?: $si->xpath('.//t') ?: [];
                $out[] = implode('', array_map('strval', $parts));
            }
        }
        $r->close();
        return $out;
    }

    /** The first sheet listed in the workbook, through its relationship. */
    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $wb   = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    return str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }
}
