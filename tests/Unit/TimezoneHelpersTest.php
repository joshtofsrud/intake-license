<?php

// MARKER-TZ-WAVE4 — timezone regression harness. Standalone PHPUnit (no
// Laravel boot, no DB): exercises the two helpers every day-boundary and
// bucketing query is built on. If these fail, the whole timezone-correctness
// story fails with them.
//
// Run: vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/TimezoneHelpersTest.php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/helpers.php';

class TimezoneHelpersTest extends TestCase
{
    // ---------- tenant_day_utc_range ----------

    public function test_normal_pacific_day_bounds(): void
    {
        [$s, $e] = tenant_day_utc_range('2026-07-16', 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 07:00:00', $e->toDateTimeString());
        $this->assertSame('UTC', $s->timezoneName);
    }

    public function test_carbon_input_uses_its_local_day(): void
    {
        // 11 PM Pacific on the 16th is already the 17th in UTC — the range
        // must still describe the tenant's 16th.
        $lateEvening = Carbon::parse('2026-07-16 23:00:00', 'America/Los_Angeles');
        [$s, $e] = tenant_day_utc_range($lateEvening, 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 07:00:00', $e->toDateTimeString());
    }

    public function test_utc_carbon_converts_to_tenant_day(): void
    {
        // 02:00 UTC on the 17th = 7 PM Pacific on the 16th.
        $utcInstant = Carbon::parse('2026-07-17 02:00:00', 'UTC');
        [$s, $e] = tenant_day_utc_range($utcInstant, 'America/Los_Angeles');
        $this->assertSame('2026-07-16 07:00:00', $s->toDateTimeString());
    }

    public function test_spring_forward_day_is_23_hours(): void
    {
        // US spring forward 2026: March 8.
        [$s, $e] = tenant_day_utc_range('2026-03-08', 'America/Los_Angeles');
        $this->assertSame(23 * 3600, $e->timestamp - $s->timestamp);
        $this->assertSame('2026-03-08 08:00:00', $s->toDateTimeString()); // PST -8
        $this->assertSame('2026-03-09 07:00:00', $e->toDateTimeString()); // PDT -7
    }

    public function test_fall_back_day_is_25_hours(): void
    {
        // US fall back 2026: November 1.
        [$s, $e] = tenant_day_utc_range('2026-11-01', 'America/Los_Angeles');
        $this->assertSame(25 * 3600, $e->timestamp - $s->timestamp);
    }

    public function test_utc_tenant_day_is_identity(): void
    {
        [$s, $e] = tenant_day_utc_range('2026-07-16', 'UTC');
        $this->assertSame('2026-07-16 00:00:00', $s->toDateTimeString());
        $this->assertSame('2026-07-17 00:00:00', $e->toDateTimeString());
    }

    // ---------- tenant_tz_offset_expr ----------

    public function test_no_transition_yields_simple_interval(): void
    {
        $start = Carbon::parse('2026-07-01 07:00:00', 'UTC');
        $end   = Carbon::parse('2026-07-15 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);
        $this->assertSame('DATE_ADD(recorded_at, INTERVAL ? SECOND)', $expr);
        $this->assertSame([-7 * 3600], $bind); // PDT all range
    }

    public function test_spring_forward_range_builds_case(): void
    {
        // Range straddling the 2026-03-08 10:00 UTC transition (2 AM PST).
        $start = Carbon::parse('2026-03-07 08:00:00', 'UTC');
        $end   = Carbon::parse('2026-03-10 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);

        $this->assertStringContainsString('CASE WHEN recorded_at < ?', $expr);
        // Bindings: [transition instant, offset before, offset after]
        $this->assertSame('2026-03-08 10:00:00', $bind[0]);
        $this->assertSame(-8 * 3600, $bind[1]); // PST before
        $this->assertSame(-7 * 3600, $bind[2]); // PDT after
    }

    public function test_offsets_bucket_rows_onto_correct_local_days(): void
    {
        // The bug this kills: a sale at 2026-03-07 23:30 Pacific (07:30 UTC
        // next day, PST era) bucketed with TODAY'S July offset (-7) lands on
        // March 8. With the era-correct offset (-8) it stays on March 7.
        $start = Carbon::parse('2026-03-07 08:00:00', 'UTC');
        $end   = Carbon::parse('2026-03-10 07:00:00', 'UTC');
        [$expr, $bind] = tenant_tz_offset_expr('recorded_at', 'America/Los_Angeles', $start, $end);

        $saleUtc = Carbon::parse('2026-03-08 07:30:00', 'UTC'); // 11:30 PM Mar 7 PST
        // Emulate the SQL CASE in PHP with the returned bindings.
        $offset = $saleUtc->toDateTimeString() < $bind[0] ? $bind[1] : $bind[2];
        $localDay = $saleUtc->copy()->addSeconds($offset)->toDateString();
        $this->assertSame('2026-03-07', $localDay);

        $wrongDay = $saleUtc->copy()->addSeconds(-7 * 3600)->toDateString(); // today's-offset bug
        $this->assertSame('2026-03-08', $wrongDay);
    }
}
