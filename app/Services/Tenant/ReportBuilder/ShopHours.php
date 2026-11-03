<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ShopHours — how many minutes the shop was open across a range, from its opening-hours rules
 * (a default per weekday, plus one-off overrides for a date). A closed day adds nothing; a day with
 * no usable rule counts as 8 hours. Used to turn booked hours into a utilization percentage.
 * (MARKER-REPORT-TABS-ENGINE)
 */
class ShopHours
{
    public function __construct(private readonly Tenant $tenant) {}

    public function minutes(Carbon $from, Carbon $to): int
    {
        $defaults = DB::table('tenant_capacity_rules')->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'default')->whereNull('specific_date')
            ->get(['day_of_week', 'is_closed', 'open_time', 'close_time'])->keyBy('day_of_week');
        $overrides = DB::table('tenant_capacity_rules')->where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'override')->whereBetween('specific_date', [$from->toDateString(), $to->toDateString()])
            ->get(['specific_date', 'is_closed', 'open_time', 'close_time'])->keyBy(fn ($r) => substr((string) $r->specific_date, 0, 10));

        $total = 0;
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $rule = $overrides->get($d->toDateString()) ?? $defaults->get($d->dayOfWeek);
            if (!$rule) { $total += 480; continue; }
            if (!empty($rule->is_closed)) continue;
            if (empty($rule->open_time) || empty($rule->close_time)) { $total += 480; continue; }
            try {
                $total += max(0, Carbon::parse($rule->open_time)->diffInMinutes(Carbon::parse($rule->close_time), true));
            } catch (\Throwable $e) {
                $total += 480;
            }
        }
        return (int) $total;
    }
}
