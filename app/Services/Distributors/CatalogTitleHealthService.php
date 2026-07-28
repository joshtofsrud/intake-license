<?php

// MARKER-TITLE-SCOPES

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;

/**
 * Looks at a sample of rows from one distributor category and reports what
 * is wrong with the titles the current rule produces.
 *
 * Everything here is judged from the DISTRIBUTOR's feed data. Tenant
 * categories and tenant item names are deliberately out of scope.
 */
class CatalogTitleHealthService
{
    /** Rows sampled per scope. Bounded on purpose — this runs 1,284 times. */
    public const SAMPLE = 200;

    public function __construct(private CatalogTitleComposer $composer) {}

    /**
     * @param  \Illuminate\Support\Collection<int,PlatformDistributorCatalog> $rows
     * @return array{flags:array,sample_title:?string}
     */
    public function inspect(string $distributorCode, $rows): array
    {
        if ($rows->isEmpty()) {
            return ['flags' => [], 'sample_title' => null];
        }

        $titles = [];
        $emptyToken = [];      // token => count of rows where it resolved blank
        $noBrand = 0;
        $tpiLooking = 0;

        foreach ($rows as $row) {
            $parts    = $this->composer->partsFromRow($row);
            $composed = $this->composer->compose($distributorCode, $parts);
            $titles[] = $composed['title'];

            if (trim((string) $row->manufacturer) === '') {
                $noBrand++;
            }

            // A size that looks like a thread count while the row also carries
            // a real size attribute that disagrees. This is the 60×2 signature.
            $size = trim((string) ($composed['size'] ?? ''));
            if ($size !== '' && preg_match('/^\d{2,3}([x\x{d7}]\d)?$/u', $size)) {
                $labeled = $this->attr($row, ['Labeled Size', 'Size']);
                if ($labeled !== '' && stripos($labeled, $size) === false) {
                    $tpiLooking++;
                }
            }

            foreach ($this->tokensIn($distributorCode) as $token) {
                if (trim($this->resolveOne($token, $composed, $parts)) === '') {
                    $emptyToken[$token] = ($emptyToken[$token] ?? 0) + 1;
                }
            }
        }

        $n = count($titles);
        $flags = [];

        if ($tpiLooking > 0) {
            $pct = (int) round($tpiLooking / $n * 100);
            $flags[] = $this->flag('size_from_tpi', 'size looks like a thread count',
                'bad', "{$pct}% of sampled items have a Labeled Size that disagrees with {size}");
        }

        foreach ($emptyToken as $token => $count) {
            if ($count / $n > 0.5) {
                $pct = (int) round($count / $n * 100);
                $flags[] = $this->flag('token_empty', "{$token} is usually empty",
                    'warn', "blank on {$pct}% of sampled items");
            }
        }

        $distinct = count(array_unique($titles));
        if ($n >= 20 && $distinct / $n < 0.6) {
            $flags[] = $this->flag('duplicates', 'many items share a title',
                'warn', "{$distinct} distinct titles across {$n} sampled items");
        }

        $avg = (int) round(array_sum(array_map('mb_strlen', $titles)) / $n);
        if ($avg > 90) {
            $flags[] = $this->flag('too_long', 'titles run long',
                'warn', "averaging {$avg} characters");
        }

        if ($noBrand / $n > 0.1) {
            $pct = (int) round($noBrand / $n * 100);
            $flags[] = $this->flag('missing_brand', 'brand missing',
                'warn', "no manufacturer on {$pct}% of sampled items");
        }

        return ['flags' => $flags, 'sample_title' => $titles[0] ?? null];
    }

    private function flag(string $code, string $label, string $severity, string $detail): array
    {
        return compact('code', 'label', 'severity', 'detail');
    }

    /** Tokens used by the effective title template for this distributor. */
    private function tokensIn(string $code): array
    {
        static $cache = [];
        if (! isset($cache[$code])) {
            $tpl = $this->composer->titleTemplateFor($code);
            preg_match_all('/\{([^}]+)\}/', $tpl, $m);
            $cache[$code] = array_values(array_unique(array_map('trim', $m[1] ?? [])));
        }
        return $cache[$code];
    }

    /** Best-effort single-token resolution against an already-composed row. */
    private function resolveOne(string $token, array $composed, array $parts): string
    {
        if ($token === 'size')  return (string) ($composed['size'] ?? '');
        if ($token === 'color') return (string) ($composed['color'] ?? '');
        if (str_starts_with($token, 'attr:')) {
            $want = trim(substr($token, 5));
            foreach (($parts['attributes'] ?? []) as $a) {
                if (is_array($a) && isset($a['Name'], $a['Value'])
                    && strcasecmp((string) $a['Name'], $want) === 0) {
                    return (string) $a['Value'];
                }
            }
            return '';
        }
        $map = ['brand' => 'brand', 'model' => 'model', 'mpn' => 'mpn', 'unit' => 'unit'];
        return isset($map[$token]) ? (string) ($parts[$map[$token]] ?? '') : '';
    }

    private function attr(PlatformDistributorCatalog $row, array $names): string
    {
        foreach (($row->attributes ?? []) as $a) {
            if (! is_array($a) || ! isset($a['Name'], $a['Value'])) continue;
            foreach ($names as $want) {
                if (strcasecmp((string) $a['Name'], $want) === 0) {
                    return trim((string) $a['Value']);
                }
            }
        }
        return '';
    }
}
