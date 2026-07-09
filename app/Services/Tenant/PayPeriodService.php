<?php
// MARKER-PATCH-616 — pay period boundary computation + materialization.
//
// TIMEZONE: every boundary is computed in the TENANT's timezone, then the
// resulting instant is stored/compared as UTC. A "period start" of the 1st is
// midnight in the tenant's zone, NOT server-local — otherwise a late-night
// punch lands in the wrong period for tenants away from UTC.

namespace App\Services\Tenant;

use App\Models\Tenant\TenantPayPeriod;
use Carbon\Carbon;

class PayPeriodService
{
    public function __construct(private $tenant) {}

    public static function for($tenant): self
    {
        return new self($tenant);
    }

    /** Configured cycle: semi_monthly | weekly | biweekly | monthly. */
    public function cycle(): string
    {
        return $this->tenant->settings['timeclock_pay_cycle'] ?? 'semi_monthly';
    }

    public function otThresholdHours(): int
    {
        return (int) ($this->tenant->settings['timeclock_ot_threshold_hours'] ?? 40);
    }

    /**
     * Tenant-local [start, end] Carbon instants (in tenant tz) for the period
     * containing $ref (a tenant-tz Carbon; defaults to tenant-local now).
     */
    public function boundsFor(?Carbon $ref = null): array
    {
        $tz  = $this->tenant->timezone();
        $ref = $ref ? $ref->copy()->setTimezone($tz) : tnow();

        switch ($this->cycle()) {
            case 'weekly':
                $start = $ref->copy()->startOfWeek();
                $end   = $ref->copy()->endOfWeek();
                break;

            case 'biweekly':
                // Anchored to the ISO week; even/odd week pairs form 14-day periods
                // from a fixed epoch Monday so periods are stable across time.
                $epoch = Carbon::create(2024, 1, 1, 0, 0, 0, $tz)->startOfWeek();
                $weeks = (int) floor($epoch->diffInDays($ref->copy()->startOfWeek()) / 7);
                $pairStartWeeks = $weeks - ($weeks % 2);
                $start = $epoch->copy()->addWeeks($pairStartWeeks)->startOfDay();
                $end   = $start->copy()->addDays(13)->endOfDay();
                break;

            case 'monthly':
                $start = $ref->copy()->startOfMonth();
                $end   = $ref->copy()->endOfMonth();
                break;

            case 'semi_monthly':
            default:
                if ($ref->day <= 15) {
                    $start = $ref->copy()->startOfMonth();
                    $end   = $ref->copy()->startOfMonth()->addDays(14)->endOfDay(); // 15th
                } else {
                    $start = $ref->copy()->startOfMonth()->addDays(15)->startOfDay(); // 16th
                    $end   = $ref->copy()->endOfMonth();
                }
                break;
        }

        return [$start, $end];
    }

    /** Materialize (find-or-create) the period row covering $ref. */
    public function period(?Carbon $ref = null): TenantPayPeriod
    {
        [$start, $end] = $this->boundsFor($ref);
        $startUtc = $start->copy()->utc();
        $endUtc   = $end->copy()->utc();

        return TenantPayPeriod::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'starts_at' => $startUtc, 'ends_at' => $endUtc],
            ['status' => 'open']
        );
    }

    /** The current period (containing now). */
    public function current(): TenantPayPeriod
    {
        return $this->period(tnow());
    }

    /** Recent periods newest-first, materializing the last $n boundaries. */
    public function recent(int $n = 6): \Illuminate\Support\Collection
    {
        $tz = $this->tenant->timezone();
        $out = collect();
        $cursor = tnow();
        for ($i = 0; $i < $n; $i++) {
            $p = $this->period($cursor);
            $out->push($p);
            // step back one day before this period's start to land in the prior period
            [$start] = $this->boundsFor($cursor);
            $cursor = $start->copy()->subDay();
        }
        return $out->unique('id')->values();
    }
}

