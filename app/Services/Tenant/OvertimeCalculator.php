<?php
// MARKER-PATCH-617 — jurisdiction-aware overtime engine.
//
// Splits a set of punches (already net of breaks) into regular / overtime (1.5x)
// / doubletime (2x) minutes, honoring both DAILY and WEEKLY thresholds and the
// "greater of" reconciliation so hours are never counted twice.
//
// Policy (tenant settings, all optional with FLSA/WA-safe defaults):
//   timeclock_ot_weekly_hours    default 40   (0 disables weekly OT)
//   timeclock_ot_daily_hours     default 0    (e.g. 8 for CA; 0 disables daily)
//   timeclock_dt_daily_hours     default 0    (e.g. 12 for CA; 0 disables DT)
//   timeclock_seventh_day_rule   default false (CA 7th-consecutive-day)
//
// Defaults (daily/DT = 0, weekly = 40) reproduce the old weekly-only behavior,
// which is correct for Washington and federal FLSA.
//
// TIMEZONE: day and week bucketing use tenant-local calendar (tlocal_carbon).

namespace App\Services\Tenant;

class OvertimeCalculator
{
    private int $weeklyMin;
    private int $dailyMin;
    private int $dtDailyMin;
    private bool $seventhDay;

    public function __construct($tenant)
    {
        $s = $tenant->settings ?? [];
        // Back-compat: old single setting timeclock_ot_threshold_hours maps to weekly.
        $weekly = $s['timeclock_ot_weekly_hours'] ?? ($s['timeclock_ot_threshold_hours'] ?? 40);
        $this->weeklyMin   = (int) $weekly * 60;
        $this->dailyMin    = (int) ($s['timeclock_ot_daily_hours'] ?? 0) * 60;
        $this->dtDailyMin  = (int) ($s['timeclock_dt_daily_hours'] ?? 0) * 60;
        $this->seventhDay  = (bool) ($s['timeclock_seventh_day_rule'] ?? false);
    }

    /**
     * @param array $dayMinutes  tenant-local 'Y-m-d' => minutes worked that day
     * @return array ['regular'=>int, 'ot'=>int, 'dt'=>int]
     */
    public function split(array $dayMinutes): array
    {
        if (empty($dayMinutes)) return ['regular' => 0, 'ot' => 0, 'dt' => 0];

        ksort($dayMinutes); // chronological for the 7th-day streak

        // ---- Pass 1: DAILY split (per calendar day) ----
        $dailyReg = 0; $dailyOt = 0; $dailyDt = 0;
        // Track consecutive-day streaks per ISO week for the 7th-day rule.
        $streak = 0; $prevDate = null;

        foreach ($dayMinutes as $date => $mins) {
            if ($mins <= 0) { $streak = 0; $prevDate = $date; continue; }

            // streak bookkeeping (consecutive calendar days worked)
            if ($prevDate !== null && \Carbon\Carbon::parse($date)->diffInDays(\Carbon\Carbon::parse($prevDate)) === 1) {
                $streak++;
            } else {
                $streak = 1;
            }
            $prevDate = $date;

            // CA 7th-consecutive-day: first 8h OT, beyond 8h DT — overrides normal daily.
            if ($this->seventhDay && $streak >= 7) {
                $eight = 8 * 60;
                $dailyOt += min($eight, $mins);
                $dailyDt += max(0, $mins - $eight);
                continue;
            }

            $reg = $mins; $ot = 0; $dt = 0;
            if ($this->dtDailyMin > 0 && $reg > $this->dtDailyMin) {
                $dt  = $reg - $this->dtDailyMin;
                $reg = $this->dtDailyMin;
            }
            if ($this->dailyMin > 0 && $reg > $this->dailyMin) {
                $ot += $reg - $this->dailyMin;
                $reg = $this->dailyMin;
            }
            $dailyReg += $reg; $dailyOt += $ot; $dailyDt += $dt;
        }

        // ---- Pass 2: WEEKLY split (bucket days into tenant-local weeks) ----
        $weekTotals = [];
        foreach ($dayMinutes as $date => $mins) {
            $wk = \Carbon\Carbon::parse($date)->startOfWeek()->format('Y-m-d');
            $weekTotals[$wk] = ($weekTotals[$wk] ?? 0) + max(0, $mins);
        }
        $weeklyOt = 0; $weeklyReg = 0;
        foreach ($weekTotals as $mins) {
            if ($this->weeklyMin > 0) {
                $weeklyReg += min($this->weeklyMin, $mins);
                $weeklyOt  += max(0, $mins - $this->weeklyMin);
            } else {
                $weeklyReg += $mins;
            }
        }

        // ---- Reconcile: take the GREATER OT of the two methods (no double count).
        // Daily method may also carry DT; weekly method carries none. Compare total
        // premium minutes (ot+dt weighted) and keep the richer split.
        $dailyPremium  = $dailyOt + $dailyDt;
        $weeklyPremium = $weeklyOt;

        if ($dailyPremium >= $weeklyPremium) {
            return ['regular' => $dailyReg, 'ot' => $dailyOt, 'dt' => $dailyDt];
        }
        // weekly wins: no daily DT in the pure-weekly model
        return ['regular' => $weeklyReg, 'ot' => $weeklyOt, 'dt' => 0];
    }
}

