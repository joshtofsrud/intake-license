<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT1 — thin CSV reader.
 *
 * Streams with fgetcsv rather than loading the file, so a 50k-row export
 * doesn't sit in memory. Encoding is converted per line for the same reason.
 */
class CsvFile
{
    public function __construct(
        private string $path,
        private string $delimiter = ',',
        private string $encoding = 'UTF-8'
    ) {}

    /** Sniff the delimiter from the first line by counting candidates. */
    public static function detectDelimiter(string $path): string
    {
        $h = @fopen($path, 'r');
        if (! $h) {
            return ',';
        }
        $line = fgets($h) ?: '';
        fclose($h);

        $best = ','; $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestCount) { $bestCount = $n; $best = $d; }
        }

        return $best;
    }

    /** UTF-8 unless the file has bytes that aren't valid UTF-8. */
    public static function detectEncoding(string $path): string
    {
        $sample = @file_get_contents($path, false, null, 0, 65536) ?: '';

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function toUtf8(array $row): array
    {
        if ($this->encoding === 'UTF-8') {
            return $row;
        }

        return array_map(function ($v) {
            return is_string($v) ? mb_convert_encoding($v, 'UTF-8', $this->encoding) : $v;
        }, $row);
    }

    /** @return \Generator<int, array> yields [lineNumber, cells] */
    public function rows(): \Generator
    {
        $h = @fopen($this->path, 'r');
        if (! $h) {
            return;
        }

        $line = 0;
        while (($cells = fgetcsv($h, 0, $this->delimiter)) !== false) {
            $line++;
            // fgetcsv gives [null] for a blank line — skip rather than error.
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            yield [$line, $this->toUtf8($cells)];
        }

        fclose($h);
    }

    /** Header names plus the first $n data rows, for the mapping screen. */
    public function preview(bool $hasHeader, int $n = 3): array
    {
        $header = [];
        $sample = [];
        $count  = 0;

        foreach ($this->rows() as [$line, $cells]) {
            if ($hasHeader && $count === 0 && $line === 1) {
                $header = array_map(fn ($c) => trim((string) $c), $cells);
                $count++;
                continue;
            }
            $sample[] = $cells;
            $count++;
            if (count($sample) >= $n) {
                break;
            }
        }

        if (! $hasHeader) {
            $width  = $sample ? count($sample[0]) : 0;
            $header = [];
            for ($i = 0; $i < $width; $i++) {
                $header[] = 'Column ' . ($i + 1);
            }
        }

        return ['header' => $header, 'sample' => $sample];
    }

    /** Row count excluding the header, plus rows whose width doesn't match. */
    public function stats(bool $hasHeader): array
    {
        $rows = 0; $ragged = 0; $width = null; $first = true;

        foreach ($this->rows() as [$line, $cells]) {
            if ($hasHeader && $first) { $width = count($cells); $first = false; continue; }
            if ($width === null) { $width = count($cells); }
            if (count($cells) !== $width) { $ragged++; }
            $rows++;
            $first = false;
        }

        return ['rows' => $rows, 'ragged' => $ragged, 'width' => $width ?? 0];
    }
}
