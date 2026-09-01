<?php

namespace App\Services\Platform;

use App\Models\PlatformBooking;
use App\Models\PlatformBookingSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MARKER-SCHED-GOOGLE
 *
 * One connected Google account (settings-backed), used for two things:
 *   - freebusy on the primary calendar → platform_booking_busy, which
 *     BookingAvailabilityService::busy() reads to hide slots
 *   - one calendar event per booking (insert / patch / delete), with a
 *     Meet link when the type wants one
 *
 * Every public method that touches the network swallows failures into
 * the log + google_last_error; a Google outage never blocks a booking.
 */
class GoogleCalendarService
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API       = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES    = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';

    // ---- state ---------------------------------------------------------

    public function configured(): bool
    {
        return (bool) (config('services.google.client_id') && config('services.google.client_secret'));
    }

    public function connected(): bool
    {
        return $this->configured() && PlatformBookingSetting::get('google_refresh_token', '') !== '';
    }

    public function email(): ?string
    {
        return PlatformBookingSetting::get('google_email', '') ?: null;
    }

    public function blockBusy(): bool   { return PlatformBookingSetting::get('google_block_busy', '1') === '1'; }
    public function writeEvents(): bool { return PlatformBookingSetting::get('google_write_events', '1') === '1'; }
    public function createMeet(): bool  { return PlatformBookingSetting::get('google_create_meet', '1') === '1'; }

    public function status(): array
    {
        return [
            'configured'   => $this->configured(),
            'connected'    => $this->connected(),
            'email'        => $this->email(),
            'connected_at' => PlatformBookingSetting::get('google_connected_at', ''),
            'last_sync_at' => PlatformBookingSetting::get('google_last_sync_at', ''),
            'last_error'   => PlatformBookingSetting::get('google_last_error', ''),
            'block_busy'   => $this->blockBusy(),
            'write_events' => $this->writeEvents(),
            'create_meet'  => $this->createMeet(),
        ];
    }

    // ---- OAuth -----------------------------------------------------------

    public function redirectUri(): string
    {
        return url('/admin/scheduling-google/callback');
    }

    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',   // forces a refresh token every time
            'state'         => $state,
        ]);
    }

    /** @return string|null error message, null on success */
    public function handleCallback(string $code): ?string
    {
        $r = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (! $r->ok() || ! $r->json('access_token')) {
            $msg = 'Token exchange failed: ' . ($r->json('error_description') ?? $r->json('error') ?? $r->status());
            $this->fail($msg);
            return $msg;
        }
        if (! $r->json('refresh_token')) {
            $msg = "Google didn't return a refresh token — remove Intake under myaccount.google.com → Security → Third-party access, then connect again.";
            $this->fail($msg);
            return $msg;
        }

        PlatformBookingSetting::put('google_access_token', Crypt::encryptString($r->json('access_token')));
        PlatformBookingSetting::put('google_refresh_token', Crypt::encryptString($r->json('refresh_token')));
        PlatformBookingSetting::put('google_expires_at', (string) now()->addSeconds((int) $r->json('expires_in', 3600) - 60)->timestamp);
        PlatformBookingSetting::put('google_connected_at', now()->toIso8601String());
        PlatformBookingSetting::put('google_last_error', '');

        // Which account is this? The primary calendar's id is the address.
        $cal = $this->get('/calendars/primary');
        PlatformBookingSetting::put('google_email', $cal['id'] ?? '');

        $this->syncBusy();
        return null;
    }

    public function disconnect(): void
    {
        $token = $this->accessToken(false);
        if ($token) {
            try { Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]); } catch (\Throwable) {}
        }
        foreach (['google_access_token', 'google_refresh_token', 'google_expires_at', 'google_email', 'google_connected_at', 'google_last_sync_at', 'google_last_error'] as $k) {
            PlatformBookingSetting::put($k, '');
        }
        DB::table('platform_booking_busy')->where('source', 'google')->delete();
    }

    private function accessToken(bool $refresh = true): ?string
    {
        if (! $this->connected()) return null;
        $exp = (int) PlatformBookingSetting::get('google_expires_at', '0');
        $enc = PlatformBookingSetting::get('google_access_token', '');
        if ($enc && $exp > time()) {
            try { return Crypt::decryptString($enc); } catch (\Throwable) {}
        }
        if (! $refresh) return null;

        try {
            $refreshToken = Crypt::decryptString(PlatformBookingSetting::get('google_refresh_token', ''));
        } catch (\Throwable) {
            $this->fail('Stored refresh token unreadable (APP_KEY changed?) — reconnect.');
            return null;
        }
        $r = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type'    => 'refresh_token',
        ]);
        if (! $r->ok() || ! $r->json('access_token')) {
            $this->fail('Token refresh failed: ' . ($r->json('error_description') ?? $r->json('error') ?? $r->status()) . ' — reconnect on Availability.');
            return null;
        }
        PlatformBookingSetting::put('google_access_token', Crypt::encryptString($r->json('access_token')));
        PlatformBookingSetting::put('google_expires_at', (string) now()->addSeconds((int) $r->json('expires_in', 3600) - 60)->timestamp);
        return $r->json('access_token');
    }

    // ---- busy time -------------------------------------------------------

    /** Pull freebusy for the booking window into platform_booking_busy. */
    public function syncBusy(): bool
    {
        if (! $this->connected()) return false;
        $tz    = PlatformBookingSetting::get('timezone');
        $weeks = (int) PlatformBookingSetting::get('window_weeks');
        $from  = CarbonImmutable::now($tz)->startOfDay()->utc();
        $to    = CarbonImmutable::now($tz)->addWeeks(max(1, $weeks))->endOfDay()->utc();

        $res = $this->post('/freeBusy', [
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'items'   => [['id' => 'primary']],
        ]);
        if ($res === null) return false;

        $busy = $res['calendars']['primary']['busy'] ?? [];
        $rows = [];
        foreach ($busy as $b) {
            if (empty($b['start']) || empty($b['end'])) continue;
            $rows[] = [
                'source'     => 'google',
                'starts_at'  => CarbonImmutable::parse($b['start'])->utc(),
                'ends_at'    => CarbonImmutable::parse($b['end'])->utc(),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::transaction(function () use ($rows) {
            DB::table('platform_booking_busy')->where('source', 'google')->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('platform_booking_busy')->insert($chunk);
            }
        });
        PlatformBookingSetting::put('google_last_sync_at', now()->toIso8601String());
        PlatformBookingSetting::put('google_last_error', '');
        return true;
    }

    // ---- events ----------------------------------------------------------

    /** Called from PlatformBooking::created. Saves quietly — no re-trigger. */
    public function onBookingCreated(PlatformBooking $b): void
    {
        if (! $this->connected() || ! $this->writeEvents() || ! $b->isActive()) return;
        $wantMeet = $this->createMeet() && $b->location_mode === 'meet';
        $body = $this->eventBody($b);
        if ($wantMeet) {
            $body['conferenceData'] = ['createRequest' => [
                'requestId'             => 'intake-' . $b->token,
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
        }
        $ev = $this->post('/calendars/primary/events?conferenceDataVersion=1&sendUpdates=none', $body);
        if ($ev === null) return;

        $fill = ['google_event_id' => $ev['id'] ?? null];
        if ($wantMeet && ! empty($ev['hangoutLink'])) {
            $fill['location_detail'] = $ev['hangoutLink'];
        }
        $b->forceFill($fill)->saveQuietly();
        $b->logEvent('calendar_event_created', 'system', ['meet' => $fill['location_detail'] ?? null]);
    }

    /** Called from PlatformBooking::updated when time or status changed. */
    public function onBookingUpdated(PlatformBooking $b): void
    {
        if (! $this->connected() || ! $this->writeEvents()) return;
        if (! $b->google_event_id) {
            if ($b->isActive()) $this->onBookingCreated($b);
            return;
        }
        if (! $b->isActive()) {
            if ($this->delete('/calendars/primary/events/' . rawurlencode($b->google_event_id) . '?sendUpdates=none') !== null) {
                // From the deleted hook the row is gone: save() would re-INSERT it.
                if ($b->exists) {
                    $b->forceFill(['google_event_id' => null])->saveQuietly();
                    $b->logEvent('calendar_event_removed', 'system');
                }
            }
            return;
        }
        if ($this->patch('/calendars/primary/events/' . rawurlencode($b->google_event_id) . '?sendUpdates=none', $this->eventBody($b)) !== null) {
            $b->logEvent('calendar_event_updated', 'system');
        }
    }

    /** Active bookings that never got an event (connected after the fact). */
    public function backfillEvents(int $limit = 25): int
    {
        if (! $this->connected() || ! $this->writeEvents()) return 0;
        $n = 0;
        PlatformBooking::active()->whereNull('google_event_id')->where('starts_at', '>', now())
            ->orderBy('starts_at')->limit($limit)->get()
            ->each(function ($b) use (&$n) { $this->onBookingCreated($b); if ($b->google_event_id) $n++; });
        return $n;
    }

    private function eventBody(PlatformBooking $b): array
    {
        $type  = $b->type?->name ?? 'Call';
        $lines = [];
        if ($b->email)   $lines[] = 'Email: ' . $b->email;
        if ($b->phone)   $lines[] = 'Phone: ' . $b->phone;
        if ($b->company) $lines[] = 'Business: ' . $b->company;
        foreach (($b->type?->questionList() ?? []) as $q) {
            $v = $b->answers[$q['key']] ?? '';
            if ($v !== '' && $q['key'] !== 'company') $lines[] = $q['label'] . ': ' . $v;
        }
        $lines[] = '';
        $lines[] = 'Manage in Intake: ' . url('/admin/scheduling');
        $body = [
            'summary'     => $type . ' — ' . $b->name . ($b->company ? ' (' . $b->company . ')' : ''),
            'description' => implode("\n", $lines),
            'start'       => ['dateTime' => $b->starts_at->copy()->utc()->toRfc3339String(), 'timeZone' => 'UTC'],
            'end'         => ['dateTime' => $b->ends_at->copy()->utc()->toRfc3339String(),   'timeZone' => 'UTC'],
            'reminders'   => ['useDefault' => true],
        ];
        if ($b->location_mode === 'phone' && $b->location_detail) {
            $body['location'] = 'Call ' . $b->location_detail;
        } elseif ($b->location_mode === 'in_person' && $b->location_detail) {
            $body['location'] = $b->location_detail;
        }
        return $body;
    }

    // ---- HTTP ------------------------------------------------------------

    private function request(string $method, string $path, ?array $json = null): ?array
    {
        $token = $this->accessToken();
        if (! $token) return null;
        try {
            $req = Http::withToken($token)->acceptJson()->timeout(15);
            $url = self::API . $path;
            $r = match ($method) {
                'GET'    => $req->get($url),
                'POST'   => $req->post($url, $json ?? []),
                'PATCH'  => $req->patch($url, $json ?? []),
                'DELETE' => $req->delete($url),
            };
            if ($r->successful()) {
                return $r->json() ?? [];
            }
            $this->fail("Google {$method} {$path} → {$r->status()}: " . ($r->json('error.message') ?? Str::limit($r->body(), 200)));
            return null;
        } catch (\Throwable $e) {
            $this->fail("Google {$method} {$path} threw: " . $e->getMessage());
            return null;
        }
    }

    private function get(string $path): ?array          { return $this->request('GET', $path); }
    private function post(string $path, array $j): ?array { return $this->request('POST', $path, $j); }
    private function patch(string $path, array $j): ?array { return $this->request('PATCH', $path, $j); }
    private function delete(string $path): ?array       { return $this->request('DELETE', $path); }

    private function fail(string $msg): void
    {
        Log::warning('MARKER-SCHED-GOOGLE ' . $msg);
        PlatformBookingSetting::put('google_last_error', Str::limit($msg, 500));
    }
}
