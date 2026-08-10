<?php

namespace App\Http\Controllers\Platform;

// MARKER-MKTTRAFFIC — the marketing site's own funnel endpoint.
//
// The tenant tracker posts to /funnel/track, which is declared inside the
// TENANT host group and resolves a tenant from the host. intake.works has no
// tenant host, so marketing pages could never reach it — which is why the
// marketing site has recorded nothing at all. This is the same contract on the
// platform host, writing under the platform tenant.

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingFunnelController extends Controller
{
    /** Event types the marketing site is allowed to record. */
    public const ALLOWED = [
        'page_view',
        'pricing_viewed',
        'quiz_started',
        'quiz_completed',
        'contact_submitted',
        'signup_started',    // reserved — self-serve signup not built yet
        'signup_completed',  // reserved
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'session_id'   => ['required', 'string', 'max:64'],
            'event_type'   => ['required', 'string', 'max:32'],
            'path'         => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string', 'max:2048'],
            'utm_source'   => ['nullable', 'string', 'max:100'],
            'utm_medium'   => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:191'],
            'device'       => ['nullable', 'string', 'max:12'],
            'step'         => ['nullable', 'string', 'max:48'],
        ]);

        if (! in_array($data['event_type'], self::ALLOWED, true)) {
            return response()->json(['ok' => false], 204);
        }

        self::record($data['event_type'], $data);

        return response()->json(['ok' => true]);
    }

    /**
     * Record a marketing funnel event. Also called server-side (quiz, contact)
     * where the server is the honest place to know the thing happened.
     * Best-effort: analytics must never break a page.
     */
    public static function record(string $eventType, array $data = []): void
    {
        try {
            $tenant = Tenant::where('is_platform', true)->first();
            if (! $tenant) {
                return;
            }

            $referrer = $data['referrer_url'] ?? null;
            $host     = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

            DB::table('tenant_funnel_events')->insert([
                'tenant_id'       => $tenant->id,
                'session_id'      => substr((string) ($data['session_id'] ?? 'server'), 0, 64),
                'event_type'      => $eventType,
                'path'            => $data['path'] ?? null,
                'referrer_domain' => $host ? substr($host, 0, 191) : null,
                'referrer_url'    => $referrer,
                'utm_source'      => $data['utm_source'] ?? null,
                'utm_medium'      => $data['utm_medium'] ?? null,
                'utm_campaign'    => $data['utm_campaign'] ?? null,
                'device'          => $data['device'] ?? null,
                'step'            => $data['step'] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('marketing funnel event failed', [
                'event' => $eventType, 'error' => $e->getMessage(),
            ]);
        }
    }
}
