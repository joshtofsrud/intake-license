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

    /** @return array<int, array{label:string, count:int, unit:string, note:?string}> */
    public function stages(): array
    {
        $s = $this->cumulativeSessions();
        $visitors = $s['pv'];
        $pricing  = $s['pr'];
        $quiz     = $s['qz'];
        $contact  = $s['ct'];

        // Tenants created in the window — the only stage that is a real outcome.
        $tenants = (int) Tenant::query()
            ->where('is_platform', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->count();

        $signupStarted = $s['ss'];

        return [
            ['label' => 'Visited the site',   'count' => $visitors, 'unit' => 'sessions', 'note' => null],
            ['label' => 'Viewed pricing',     'count' => $pricing,  'unit' => 'sessions', 'note' => null],
            ['label' => 'Completed the quiz', 'count' => $quiz,     'unit' => 'sessions', 'note' => null],
            ['label' => 'Got in touch',       'count' => $contact,  'unit' => 'sessions', 'note' => null],
            ['label' => 'Started signup',     'count' => $signupStarted, 'unit' => 'sessions',
             'note'  => 'Self-serve signup isn\'t built yet — this stays at zero until it ships.'],
            ['label' => 'Became a tenant',    'count' => $tenants,  'unit' => 'accounts',
             'note'  => 'Counts tenant records created in this window, however they arrived.'],
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
                ->select('path', DB::raw('COUNT(DISTINCT session_id) as sessions'))
                ->groupBy('path')->orderByDesc('sessions')->limit(10)
                ->pluck('sessions', 'path')->all();
        }

        return $out;
    }
}
