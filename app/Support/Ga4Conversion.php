<?php

namespace App\Support;

use App\Models\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GA4-CONVERSIONS: server-side GA4 events for the two conversions that
 * never land on a trackable intake.works page. Signup ends on the shop's own
 * subdomain, and a booking ends on its private manage link (which analytics
 * deliberately never loads on). The event carries the visitor's own GA
 * client id, read from the _ga cookie on this same request, so Google Ads can
 * credit the ad click that brought them. Sent after the response, so the
 * visitor never waits on Google; any failure is logged and never thrown.
 */
class Ga4Conversion
{
    public static function send(Request $request, string $event, array $params = []): void
    {
        try {
            $s      = SiteSettings::current();
            $id     = trim((string) ($s->ga4_id ?? ''));
            $secret = trim((string) ($s->ga4_api_secret ?? ''));
            if (! preg_match('/^G-[A-Z0-9]{4,20}$/i', $id) || $secret === '') {
                return;
            }
            // Intake staff signed in to master admin are testing, not converting.
            if (auth('web')->check()) {
                return;
            }

            $clientId  = self::clientId() ?? (random_int(100000000, 999999999) . '.' . time());
            $sessionId = self::sessionId($id);

            $p = array_filter($params, fn ($v) => $v !== null && $v !== '');
            $p['engagement_time_msec'] = 1;
            if ($sessionId) {
                $p['session_id'] = $sessionId;
            }

            $payload = ['client_id' => $clientId, 'events' => [['name' => $event, 'params' => $p]]];
            $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . urlencode($id) . '&api_secret=' . urlencode($secret);

            app()->terminating(function () use ($url, $payload, $event) {
                try {
                    $r = Http::timeout(4)->asJson()->post($url, $payload);
                    if (! $r->successful()) {
                        Log::warning('GA4 conversion not accepted', ['event' => $event, 'status' => $r->status()]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('GA4 conversion failed', ['event' => $event, 'error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('GA4 conversion skipped', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * "GA1.1.123456789.1700000000" -> "123456789.1700000000". Read raw: Laravel's
     * cookie decryption turns cookies it didn't set (like _ga) into null.
     */
    protected static function clientId(): ?string
    {
        $c = (string) ($_COOKIE['_ga'] ?? '');
        return preg_match('/^GA\d\.\d+\.(\d+\.\d+)$/', $c, $m) ? $m[1] : null;
    }

    /** From _ga_<stream>: "GS1.1.1700000000.3.1..." or "GS2.1.s1700000000$o3$g1...". */
    protected static function sessionId(string $measurementId): ?string
    {
        $c = (string) ($_COOKIE['_ga_' . strtoupper(substr($measurementId, 2))] ?? '');
        if (preg_match('/^GS1\.\d+\.(\d+)\./', $c, $m)) {
            return $m[1];
        }
        if (preg_match('/^GS2\.\d+\.s(\d+)/', $c, $m)) {
            return $m[1];
        }
        return null;
    }
}
