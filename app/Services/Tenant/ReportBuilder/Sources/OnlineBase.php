<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;

/**
 * MARKER-ONLINE-REPORTS: shared by the online-store sources. Their dates are UTC timestamps, so
 * rows are grouped by UTC hour in SQL and folded into the shop's own local days here.
 */
abstract class OnlineBase extends Source
{
    protected function utc(Carbon $from, Carbon $to): array
    {
        [$a, $b] = $this->p->utcBounds($from, $to);
        return [$a instanceof Carbon ? $a->format('Y-m-d H:i:s') : (string) $a, $b instanceof Carbon ? $b->format('Y-m-d H:i:s') : (string) $b];
    }

    /** Local bucket key and label for a time dimension, from a UTC "Y-m-d H" hour. */
    protected function timeKeyLabel(string $dim, string $utcHour): array
    {
        [$date] = $this->p->localFromUtcHour($utcHour);
        $k = $this->p->key($dim, $date);
        return [$k, $this->p->label($dim, $k)];
    }

    protected function distinct(string $table, string $col, array $extra = []): array
    {
        $out = [];
        foreach (\Illuminate\Support\Facades\DB::table($table)->where('tenant_id', $this->tid())->whereNotNull($col)->where($col, '!=', '')
            ->distinct()->orderBy($col)->pluck($col)->all() as $v) {
            $out[(string) $v] = $extra[$v] ?? (string) $v;
        }
        return $out;
    }
}
