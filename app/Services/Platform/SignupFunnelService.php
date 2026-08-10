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

    private function sessions(string $eventType): int
    {
        $id = $this->platformTenantId();
        if (! $id) {
            return 0;
        }

        return (int) DB::table('tenant_funnel_events')
            ->where('tenant_id', $id)
            ->where('event_type', $eventType)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->distinct()->count('session_id');
    }

    /** @return array<int, array{label:string, count:int, unit:string, note:?string}> */
    public function stages(): array
    {
        $visitors = $this->sessions('page_view');
        $pricing  = $this->sessions('pricing_viewed');
        $quiz     = $this->sessions('quiz_completed');
        $contact  = $this->sessions('contact_submitted');

        // Tenants created in the window — the only stage that is a real outcome.
        $tenants = (int) Tenant::query()
            ->where('is_platform', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->count();

        $signupStarted = $this->sessions('signup_started');

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
