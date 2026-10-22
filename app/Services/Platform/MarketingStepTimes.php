<?php
// MARKER-STEP-TIMES
//
// When each step on Marketing traffic happened, so a step can be matched to
// what was going on (an email that went out, an ad, a post). For each tile on
// "How far they got" and "What came of it": the sessions behind it, newest
// first, with the time, where the visit came from, the page it landed on and,
// for outreach links, which shop. "Became a tenant" lists the accounts.

namespace App\Services\Platform;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MarketingStepTimes
{
    public const LIMIT = 40;

    /** Tile label => event types (the labels SignupFunnelService uses). */
    public const STEPS = [
        'Visited the site'   => ['page_view'],
        'Viewed pricing'     => ['pricing_viewed'],
        'Completed the quiz' => ['quiz_completed'],
        'Got in touch'       => ['contact_submitted'],
        'Entered the demo'   => ['demo_entered'],
        'Booked a call'      => ['booking_completed'],
        'Started signup'     => ['signup_started'],
    ];

    /** @return array<string, array<int, array{when: string, source: string, landing: string, shop: ?string, session: string}>> */
    public static function forWindow($start, $end): array
    {
        $platform = Tenant::where('is_platform', true)->first();
        if (! $platform) return [];
        $tz = $platform->timezone ?? config('app.timezone', 'UTC');
        $out = [];
        try {
            $sessions = [];
            foreach (self::STEPS as $label => $types) {
                $rows = DB::table('tenant_funnel_events')
                    ->where('tenant_id', $platform->id)
                    ->whereIn('event_type', $types)
                    ->where('created_at', '>=', $start)->where('created_at', '<', $end)
                    ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); })
                    ->selectRaw('session_id, MIN(created_at) as at')
                    ->groupBy('session_id')
                    ->orderByDesc('at')
                    ->limit(self::LIMIT)
                    ->get();
                $out[$label] = $rows->map(fn ($r) => ['session' => (string) $r->session_id, 'at' => (string) $r->at])->all();
                foreach ($rows as $r) $sessions[(string) $r->session_id] = true;
            }

            // where each of those visits started
            $first = [];
            $ids = array_keys($sessions);
            foreach (array_chunk($ids, 500) as $chunk) {
                $minIds = DB::table('tenant_funnel_events')->where('tenant_id', $platform->id)
                    ->whereIn('session_id', $chunk)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                    ->selectRaw('MIN(id) as id')->groupBy('session_id');
                foreach (DB::table('tenant_funnel_events')->whereIn('id', $minIds)->get(['session_id', 'path', 'utm_source', 'referrer_domain']) as $e) {
                    $ref = strtolower(trim((string) $e->referrer_domain));
                    $ref = str_starts_with($ref, 'www.') ? substr($ref, 4) : $ref;
                    $own = strtolower((string) config('intake.domain', 'intake.works'));
                    $src = trim((string) $e->utm_source) !== '' ? trim((string) $e->utm_source) : ($ref !== '' && $ref !== $own ? $ref : 'direct');
                    $first[$e->session_id] = ['source' => $src === 'outreach' ? 'Email outreach' : $src, 'landing' => $e->path ?: '/'];
                }
            }
            $shops = \App\Services\Sales\OutreachResults::shopsForSessions($ids);

            foreach ($out as $label => $rows) {
                $out[$label] = array_map(fn ($r) => [
                    'when'    => CarbonImmutable::parse($r['at'])->setTimezone($tz)->format('M j, g:i a'),
                    'source'  => $first[$r['session']]['source'] ?? 'direct',
                    'landing' => $first[$r['session']]['landing'] ?? '/',
                    'shop'    => $shops[$r['session']]['shop'] ?? null,
                    'session' => $r['session'],
                ], $rows);
            }

            $out['Became a tenant'] = Tenant::query()->where('is_platform', false)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->limit(self::LIMIT)->get(['name', 'created_at'])
                ->map(fn ($t) => ['when' => $t->created_at->setTimezone($tz)->format('M j, g:i a'), 'source' => '', 'landing' => '', 'shop' => $t->name, 'session' => ''])
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }
        return $out;
    }
}
