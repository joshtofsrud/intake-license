<?php

namespace App\Services\Platform;

// MARKER-MKTSESSIONS — per-session marketing activity, mirroring the tenant
// admin's booking sessions explorer. Derived entirely from events already
// being recorded, so it works on rows collected before this shipped.

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MarketingSessionsService
{
    /** Hard cap so a busy window can't load the whole table into memory. */
    public const EVENT_LIMIT = 3000;

    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end
    ) {}

    /** Friendly label for a named event; page views show their path instead. */
    private static function label(string $eventType, ?string $path): string
    {
        return match ($eventType) {
            'page_view'         => $path ?: '/',
            'pricing_viewed'    => 'Looked at pricing',
            'quiz_started'      => 'Started the plan quiz',
            'quiz_completed'    => 'Completed the plan quiz',
            'contact_submitted' => 'Sent a message',
            'signup_started'    => 'Started signup',
            'signup_completed'  => 'Finished signup',
            default             => $eventType,
        };
    }

    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        if ($seconds < 3600) {
            return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** @return array<int, array<string, mixed>> newest session first */
    public function recent(int $limit = 60): array
    {
        $tenant = Tenant::where('is_platform', true)->first();
        if (! $tenant) {
            return [];
        }

        $tz = $tenant->timezone ?? config('app.timezone', 'UTC');

        $events = DB::table('tenant_funnel_events')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<',  $this->end)
            ->orderBy('created_at')
            ->limit(self::EVENT_LIMIT)
            ->get(['session_id', 'event_type', 'path', 'device', 'referrer_domain', 'utm_source', 'created_at']);

        $sessions = [];

        foreach ($events as $e) {
            $sid = $e->session_id ?: 'unknown';
            $at  = CarbonImmutable::parse($e->created_at);

            if (! isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'id'        => $sid,
                    'session'   => strlen($sid) > 8 ? substr($sid, 0, 4) . '…' . substr($sid, -3) : $sid,
                    'first_at'  => $at,
                    'last_at'   => $at,
                    'device'    => $e->device,
                    'referrer'  => $e->referrer_domain,
                    'utm'       => $e->utm_source,
                    'landing'   => null,
                    'pages'     => [],
                    'converted' => false,
                    'timeline'  => [],
                ];
            }

            $s = &$sessions[$sid];

            if ($at->greaterThan($s['last_at'])) {
                $s['last_at'] = $at;
            }
            // First non-empty wins — a later event may not carry these.
            if ($e->device && ! $s['device'])                   { $s['device']   = $e->device; }
            if ($e->referrer_domain && ! $s['referrer'])        { $s['referrer'] = $e->referrer_domain; }
            if ($e->utm_source && ! $s['utm'])                  { $s['utm']      = $e->utm_source; }

            if ($e->event_type === 'page_view') {
                $path = $e->path ?: '/';
                $s['landing'] ??= $path;
                $s['pages'][] = $path;
            }

            if (in_array($e->event_type, ['quiz_completed', 'contact_submitted', 'signup_completed'], true)) {
                $s['converted'] = true;
            }

            $s['timeline'][] = [
                'at'   => $at->setTimezone($tz)->format('g:i:s A'),
                'what' => self::label($e->event_type, $e->path),
            ];

            unset($s);
        }

        $out = [];
        foreach ($sessions as $s) {
            $pageCount = count($s['pages']);
            $seconds   = $s['last_at']->diffInSeconds($s['first_at']);

            $out[] = [
                'session'    => $s['session'],
                'time'       => $s['first_at']->setTimezone($tz)->format('g:i A'),
                'day'        => $s['first_at']->setTimezone($tz)->format('M j'),
                'sort'       => $s['first_at']->getTimestamp(),
                'landing'    => $s['landing'] ?? '—',
                'page_count' => $pageCount,
                'pages'      => $s['pages'],
                'duration'   => self::duration($seconds),
                'seconds'    => $seconds,
                'device'     => $s['device'],
                'referrer'   => $s['referrer'],
                'utm'        => $s['utm'],
                'status'     => $s['converted']
                    ? 'converted'
                    : ($pageCount > 1 ? 'browsed' : 'bounced'),
                'timeline'   => $s['timeline'],
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, $limit);
    }
}
