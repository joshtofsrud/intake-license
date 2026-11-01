<?php

namespace App\Support;

use App\Models\PlatformSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-VELOCITY-FETCH: how often the distributor velocity sweep reads each
 * distributor, set in master admin (Distributor velocity › Data coverage).
 * The sweep runs hourly and reads only the distributors that are due.
 */
class VelocityFetch
{
    /** hours => label; 0 = off */
    public const CHOICES = [4 => 'Every 4 hours', 6 => 'Every 6 hours', 8 => 'Every 8 hours', 12 => 'Twice a day', 24 => 'Once a day', 0 => 'Off'];
    public const DEFAULTS = ['BTI' => 4, 'HLC' => 4, 'QBP' => 4, 'SHIMANO' => 12];
    public const FALLBACK = 4;

    public static function hours(string $code): int
    {
        $code = strtoupper($code);
        $saved = (array) ((PlatformSettings::current()->velocity_settings ?? [])['fetch'] ?? []);
        $h = array_key_exists($code, $saved) ? (int) $saved[$code] : (self::DEFAULTS[$code] ?? self::FALLBACK);
        return array_key_exists($h, self::CHOICES) ? $h : self::FALLBACK;
    }

    /** code => hours for every distributor named. */
    public static function all(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) $out[strtoupper($c)] = self::hours($c);
        return $out;
    }

    /** When each distributor was last read by a sweep (from the run log). */
    public static function lastReads(): array
    {
        $last = [];
        DB::table('distributor_velocity_runs')->whereNotNull('finished_at')->orderByDesc('started_at')->limit(200)
            ->get(['started_at', 'results'])->each(function ($r) use (&$last) {
                foreach ((array) json_decode((string) $r->results, true) as $x) {
                    $c = $x['code'] ?? null;
                    if ($c && ! isset($last[$c]) && (int) ($x['read'] ?? 0) > 0) $last[$c] = Carbon::parse($r->started_at, 'UTC');
                }
            });
        return $last;
    }

    /** Due now? Ten minutes of slack so an hourly run at :15 isn't missed by seconds. */
    public static function due(string $code, ?Carbon $last): bool
    {
        $h = self::hours($code);
        if ($h === 0) return false;
        return ! $last || $last->lte(now()->subHours($h)->addMinutes(10));
    }
}
