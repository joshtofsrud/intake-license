<?php

namespace App\Services\Platform;

// MARKER-MKTTRAFFIC — the signup/intent funnel.
//
// Deliberately NOT modelled on the tenant booking funnel, because the shape is
// different: a booking is one session end to end, whereas signup spans anonymous
// browsing and a tenant record created later, possibly on another day. So the
// later stages count REAL OBJECTS (quiz rows, tenants) rather than sessions,
// and each stage says which it is instead of implying a single-session journey.

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SignupFunnelService
{
    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end
    ) {}

    private function platformTenantId(): ?string
    {
        return Tenant::where('is_platform', true)->value('id');
    }

    // MARKER-FUNNEL-SCOPED — one row of stage flags per session, summed
    // cumulatively: a session counts at a step only if it also hit every
    // step before it. Monotonic by construction, so the funnel can only
    // fall. One grouped query replaces five independent distinct-counts.
    /** @return array{pv:int,pr:int,qz:int,ct:int,ss:int} */
    private function cumulativeSessions(): array
    {
        $zero = ['pv' => 0, 'pr' => 0, 'qz' => 0, 'ct' => 0, 'ss' => 0];
        $id = $this->platformTenantId();
        if (! $id) {
            return $zero;
        }

        $flags = DB::table('tenant_funnel_events')
            ->where('tenant_id', $id)
            ->whereIn('event_type', [
                'page_view', 'pricing_viewed', 'quiz_completed',
                'contact_submitted', 'signup_started',
            ])
            ->whereBetween('created_at', [$this->start, $this->end])
            // MARKER-MKTREPAIR — the tiles exclude bots and this did not, so the
            // funnel's first step could sit ABOVE the Visitors tile beside it.
            // Historical crawler rows predate the ingest-side skip.
            ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); })
            ->groupBy('session_id')
            ->select([
                DB::raw("MAX(event_type = 'page_view')         as pv"),
                DB::raw("MAX(event_type = 'pricing_viewed')    as pr"),
                DB::raw("MAX(event_type = 'quiz_completed')    as qz"),
                DB::raw("MAX(event_type = 'contact_submitted') as ct"),
                DB::raw("MAX(event_type = 'signup_started')    as ss"),
            ]);

        $row = DB::query()->fromSub($flags, 's')
            ->selectRaw('COALESCE(SUM(pv), 0)                as pv')
            ->selectRaw('COALESCE(SUM(pv * pr), 0)           as pr')
            ->selectRaw('COALESCE(SUM(pv * pr * qz), 0)      as qz')
            ->selectRaw('COALESCE(SUM(pv * pr * qz * ct), 0) as ct')
            ->selectRaw('COALESCE(SUM(pv * pr * qz * ct * ss), 0) as ss')
            ->first();

        return $row ? array_map('intval', (array) $row) : $zero;
    }

    /**
     * MARKER-MKTDONE — BROWSING only, and only the steps that really are a
     * sequence. These stay cumulative: a session counts at a step only if it
     * also hit every step before it, so the funnel can only fall.
     *
     * Contact, demo, calls and signup used to sit on this same cumulative line,
     * which HID REAL CONVERSIONS — a visitor who landed on /contact from a
     * search result and wrote to you never reached "Got in touch", because they
     * had not viewed pricing and finished the quiz first. Those live in
     * outcomes() now, each counted on its own.
     *
     * @return array<int, array{label:string, count:int, unit:string, note:?string}>
     */
    public function stages(): array
    {
        $s = $this->cumulativeSessions();

        return [
            ['label' => 'Visited the site',   'count' => $s['pv'], 'unit' => 'sessions', 'note' => null],
            ['label' => 'Viewed pricing',     'count' => $s['pr'], 'unit' => 'sessions', 'note' => null],
            ['label' => 'Completed the quiz', 'count' => $s['qz'], 'unit' => 'sessions', 'note' => null],
        ];
    }

    /**
     * MARKER-MKTDONE — the things that actually count as a result, each measured
     * independently of the others and of the browsing funnel. None of these
     * requires any of the rest: someone can book a call without ever opening
     * pricing, and frequently does.
     *
     * @return array<int, array{label:string, count:int, unit:string, note:?string}>
     */
    public function outcomes(): array
    {
        $id = $this->platformTenantId();

        $sessionsFor = function (array $types) use ($id): int {
            if (! $id) return 0;

            return (int) DB::table('tenant_funnel_events')
                ->where('tenant_id', $id)
                ->whereIn('event_type', $types)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); })
                ->distinct('session_id')
                ->count('session_id');
        };

        $tenants = (int) Tenant::query()
            ->where('is_platform', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->count();

        return [
            ['label' => 'Got in touch',   'count' => $sessionsFor(['contact_submitted']), 'unit' => 'sessions',
             'note'  => 'Recorded on the server when the message saved.'],
            ['label' => 'Entered the demo', 'count' => $sessionsFor(['demo_entered']), 'unit' => 'sessions',
             'note'  => 'Recorded on the server when the demo opened.'],
            ['label' => 'Booked a call',  'count' => $sessionsFor(['booking_completed']), 'unit' => 'sessions',
             'note'  => 'Recorded on the server when the booking saved.'],
            ['label' => 'Started signup', 'count' => $sessionsFor(['signup_started']), 'unit' => 'sessions',
             'note'  => "Self-serve signup isn't built yet — this stays at zero until it ships."],
            ['label' => 'Became a tenant', 'count' => $tenants, 'unit' => 'accounts',
             'note'  => 'Accounts created in this window, however they arrived.'],
        ];
    }

    /** Intent signals that don't sit on one funnel line. */
    public function intent(): array
    {
        $out = [
            'quiz_completions'    => 0,
            'quiz_recommendation' => [],
            'industry_pages'      => [],
        ];

        if (Schema::hasTable('quiz_completions')) {
            $rows = DB::table('quiz_completions')
                ->whereBetween('created_at', [$this->start, $this->end])
                ->get(['recommendation']);

            $out['quiz_completions'] = $rows->count();
            $out['quiz_recommendation'] = $rows->groupBy('recommendation')
                ->map->count()->sortDesc()->all();
        }

        $id = $this->platformTenantId();
        if ($id) {
            $out['industry_pages'] = DB::table('tenant_funnel_events')
                ->where('tenant_id', $id)
                ->where('event_type', 'page_view')
                ->where('path', 'like', '/for/%')
                ->whereBetween('created_at', [$this->start, $this->end])
                ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); }) // MARKER-MKTREPAIR
                ->select('path', DB::raw('COUNT(DISTINCT session_id) as sessions'))
                ->groupBy('path')->orderByDesc('sessions')->limit(10)
                ->pluck('sessions', 'path')->all();
        }

        return $out;
    }
}
