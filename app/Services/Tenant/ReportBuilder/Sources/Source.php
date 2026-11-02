<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use App\Models\Tenant;
use App\Services\Tenant\ReportBuilder\Periods;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One kind of data a custom report can read. (MARKER-REPORT-BUILDER-V2)
 *
 * A source turns (breakdowns, filters, date range) into groups of raw
 * components; the engine turns components into measures. Everything a source
 * reads is scoped to the tenant, and nothing here writes.
 */
abstract class Source
{
    public const MB = ['Under 30%', '30–40%', '40–50%', '50–60%', 'Over 60%'];
    public const PB = ['Under $25', '$25–50', '$50–100', '$100–200', 'Over $200'];

    protected array $lookups = [];
    protected int $rangeDays = 30;

    public function __construct(protected readonly Tenant $tenant, protected readonly Periods $p) {}

    abstract public function meta(): array;

    /** value => label for a filter, tenant-scoped. */
    abstract public function options(string $key): array;

    /**
     * @return array{groups: array<string, array{k: array, l: array, c: array}>, total: array}
     */
    abstract public function run(array $dims, array $filters, Carbon $from, Carbon $to): array;

    abstract public function value(string $m, array $c): ?float;

    // ------------------------------------------------------------- helpers

    protected function tid(): string
    {
        return $this->tenant->id;
    }

    /** filters as key => ['is' => [...], 'not' => [...]] */
    protected function byKey(array $filters): array
    {
        $out = [];
        foreach ($filters as $f) {
            if (!isset($f['f'], $f['v']) || $f['v'] === '' || $f['v'] === null) {
                continue;
            }
            $op = ($f['op'] ?? 'is') === 'not' ? 'not' : 'is';
            $out[$f['f']][$op][] = (string) $f['v'];
        }
        return $out;
    }

    protected function sqlFilter(Builder $q, string $col, array $f): void
    {
        if (!empty($f['is'])) {
            $q->whereIn(DB::raw($col), $f['is']);
        }
        if (!empty($f['not'])) {
            $q->where(function ($w) use ($col, $f) {
                $w->whereNotIn(DB::raw($col), $f['not'])->orWhereRaw("$col IS NULL");
            });
        }
    }

    /** Does a value pass an is/not filter (for filters applied in PHP)? */
    protected function passes(?string $val, ?array $f): bool
    {
        if (!$f) {
            return true;
        }
        if (!empty($f['is']) && !in_array((string) $val, $f['is'], true)) {
            return false;
        }
        if (!empty($f['not']) && in_array((string) $val, $f['not'], true)) {
            return false;
        }
        return true;
    }

    protected static function mband(?float $pct): string
    {
        if ($pct === null) return 'none';
        return (string) ($pct < 30 ? 0 : ($pct < 40 ? 1 : ($pct < 50 ? 2 : ($pct < 60 ? 3 : 4))));
    }

    protected static function pband(?int $cents): string
    {
        if ($cents === null) return 'none';
        return (string) ($cents < 2500 ? 0 : ($cents < 5000 ? 1 : ($cents < 10000 ? 2 : ($cents < 20000 ? 3 : 4))));
    }

    protected static function bandSql(string $expr, array $edges): string
    {
        return "CASE WHEN ($expr) IS NULL THEN 'none' WHEN ($expr) < {$edges[0]} THEN '0' WHEN ($expr) < {$edges[1]} THEN '1' WHEN ($expr) < {$edges[2]} THEN '2' WHEN ($expr) < {$edges[3]} THEN '3' ELSE '4' END";
    }

    protected function bandOptions(string $dim): array
    {
        $names = $dim === 'mband' ? self::MB : self::PB;
        $out = [];
        foreach ($names as $i => $n) {
            $out[(string) $i] = $n;
        }
        $out['none'] = $dim === 'mband' ? 'No cost' : 'No price';
        return $out;
    }

    /** id => name maps, loaded once per run. */
    protected function names(string $what): array
    {
        if (isset($this->lookups[$what])) {
            return $this->lookups[$what];
        }
        $tid = $this->tid();
        $map = match ($what) {
            'loc'   => DB::table('tenant_locations')->where('tenant_id', $tid)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'user'  => DB::table('tenant_users')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all(),
            'cat'   => DB::table('tenant_inventory_categories')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all(),
            'vend'  => DB::table('tenant_vendors')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all(),
            'res'   => DB::table('tenant_resources')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all(),
            default => [],
        };
        return $this->lookups[$what] = array_map('strval', $map);
    }

    protected function activeCategories(): array
    {
        return DB::table('tenant_inventory_categories')->where('tenant_id', $this->tid())->whereNull('deleted_at')
            ->orderBy('name')->pluck('name', 'id')->map(fn ($v) => (string) $v)->all();
    }

    protected function add(array &$groups, array $keys, array $labels, array $c): void
    {
        $gk = implode("\x1f", $keys);
        if (!isset($groups[$gk])) {
            $groups[$gk] = ['k' => $keys, 'l' => $labels, 'c' => []];
        }
        foreach ($c as $n => $v) {
            if ($v === null) {
                $groups[$gk]['c'][$n] = $groups[$gk]['c'][$n] ?? null;
                continue;
            }
            $groups[$gk]['c'][$n] = ($groups[$gk]['c'][$n] ?? 0) + $v;
        }
    }

    public function setRangeDays(int $d): void
    {
        $this->rangeDays = max(1, $d);
    }

    protected static function ratio($a, $b, float $mul = 1.0): ?float
    {
        $b = (float) $b;
        return $b != 0.0 ? (float) $a / $b * $mul : null;
    }
}
