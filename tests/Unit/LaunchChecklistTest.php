<?php

namespace Tests\Unit;

use App\Support\Launch\LaunchChecks;
use App\Support\Launch\LaunchDefaults;
use App\Support\Launch\RentalRules;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** MARKER-LAUNCH-CHECKLIST */
class LaunchChecklistTest extends TestCase
{
    private function hours(): array
    {
        $days = [];
        foreach (RentalRules::DAY_NAMES as $n => $_) $days[$n] = ['open' => false, 'from' => '09:00', 'to' => '17:00'];
        $days[2] = ['open' => true, 'from' => '09:00', 'to' => '17:00'];
        $days[3] = ['open' => true, 'from' => '09:00', 'to' => '17:00'];
        $days[6] = ['open' => true, 'from' => '10:00', 'to' => '14:00'];
        return ['days' => $days, 'closed' => ['2026-11-25'], 'saved' => true];
    }

    public function test_every_default_step_uses_a_check_that_exists(): void
    {
        $catalog = LaunchChecks::catalog();
        foreach (LaunchDefaults::steps() as $module => $steps) {
            $keys = [];
            foreach ($steps as $s) {
                $this->assertTrue($s['check'] === 'confirm' || isset($catalog[$s['check']]), "$module.{$s['key']} uses unknown check {$s['check']}");
                if ($s['check'] !== 'confirm') {
                    $this->assertTrue(method_exists(LaunchChecks::class, 'c_' . $s['check']), "no method for {$s['check']}");
                    $this->assertContains($catalog[$s['check']][1], [null, $module], "{$s['check']} is not for $module");
                }
                $this->assertContains($s['level'], ['req', 'rec', 'opt']);
                $this->assertNotContains($s['key'], $keys, "duplicate key $module.{$s['key']}");
                $keys[] = $s['key'];
                if (! empty($s['pause'])) {
                    $this->assertSame('req', $s['level'], 'pause only on required steps');
                    $this->assertNotSame('confirm', $s['check'], 'pause only on automatic steps');
                }
            }
        }
    }

    public function test_every_catalog_check_has_a_method(): void
    {
        foreach (array_keys(LaunchChecks::catalog()) as $k) {
            $this->assertTrue(method_exists(LaunchChecks::class, 'c_' . $k), "missing c_$k");
        }
    }

    public function test_hours_allow_open_times_and_refuse_the_rest(): void
    {
        $h = $this->hours();
        $this->assertNull(RentalRules::refuse($h, Carbon::parse('2026-11-17 09:00'), 'pickup'));   // Tue opening
        $this->assertNull(RentalRules::refuse($h, Carbon::parse('2026-11-17 17:00'), 'return'));   // Tue closing
        $this->assertStringContainsString('between 9:00 AM and 5:00 PM', RentalRules::refuse($h, Carbon::parse('2026-11-17 08:59'), 'pickup'));
        $this->assertStringContainsString('closed on Mondays', RentalRules::refuse($h, Carbon::parse('2026-11-16 12:00'), 'pickup'));
        $this->assertStringContainsString('closed on Wednesday, Nov 25', RentalRules::refuse($h, Carbon::parse('2026-11-25 12:00'), 'return'));
        $this->assertStringContainsString('Return on Saturdays', RentalRules::refuse($h, Carbon::parse('2026-11-21 15:00'), 'return'));
    }

    public function test_no_open_day_means_not_limited(): void
    {
        $h = $this->hours();
        foreach ($h['days'] as $n => $d) $h['days'][$n]['open'] = false;
        $this->assertNull(RentalRules::refuse($h, Carbon::parse('2026-11-16 03:00'), 'pickup'));
    }

    public function test_summary_groups_matching_days(): void
    {
        $h = $this->hours();
        $h['closed'] = [];
        $this->assertSame('Tue–Wed 9:00 AM–5:00 PM, Sat 10:00 AM–2:00 PM', RentalRules::summary($h));
    }
}
