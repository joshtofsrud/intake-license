<?php

namespace App\Services;

use App\Models\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-POSTMARK-INACTIVE: Postmark's inactive-recipient list, kept in
 * Intake.
 *
 * Postmark stops delivering to an address after a hard bounce, a spam
 * complaint or an unsubscribe on its side ("inactive"). When Intake then
 * sends to it, Postmark refuses the message and sends NO webhook, so before
 * this Intake never found out: Oakridge's Oct 14 campaign was refused for 158
 * addresses that its Oct 8 campaign had already been refused for.
 *
 * So Intake asks: the suppression dump for each marketing stream (the shops'
 * campaign stream and Intake's own outreach stream), every hour and right
 * before a campaign starts sending. Sends skip anything on the list and say
 * why. An address Postmark reactivates drops off at the next sync.
 */
class PostmarkInactive
{
    public const REASONS = [
        'HardBounce' => 'bounced', 'SpamComplaint' => 'marked as spam', 'ManualSuppression' => 'unsubscribed',
    ];

    /** The marketing streams in use: [stream id => label]. */
    public static function streams(): array
    {
        $out = [];
        if ($s = EmailLedger::broadcastStream()) $out[$s] = 'Shop campaigns';
        if ($p = \App\Services\Platform\PlatformMailer::stream()) $out[$p] = ($out[$p] ?? null) ? $out[$p] . ' and Intake marketing' : 'Intake marketing';

        return $out;
    }

    /** The Postmark server token: POSTMARK_SERVER_TOKEN, else the SMTP password (Postmark uses the server token for both). */
    public static function token(): ?string
    {
        $t = (string) (config('services.postmark.token') ?: '');
        if ($t === '' && str_contains((string) config('mail.mailers.smtp.host'), 'postmarkapp.com')) {
            $t = (string) config('mail.mailers.smtp.password');
        }

        return $t !== '' ? $t : null;
    }

    /** Why Postmark has this address inactive on the stream, or null if it doesn't. */
    public static function reason(?string $stream, string $email): ?string
    {
        if (! $stream) return null;
        try {
            $r = DB::table('postmark_suppressions')->where('stream', $stream)->where('email', mb_strtolower(trim($email)))->value('reason');
        } catch (\Throwable $e) {
            return null; // table not there yet: nothing known
        }
        if ($r === null) return null;

        return self::REASONS[$r] ?? 'inactive';
    }

    /** "Postmark has this address marked inactive (unsubscribed)" */
    public static function skipText(string $reason): string
    {
        return 'Postmark has this address marked inactive (' . $reason . ')';
    }

    public static function status(): array
    {
        $raw = DB::table('platform_settings')->where('id', 1)->value('postmark_inactive_sync');
        $s = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($s) ? $s : [];
    }

    /** Syncs every marketing stream. Returns [stream => [count, error]]. Never throws. */
    public static function syncAll(): array
    {
        $out = [];
        foreach (array_keys(self::streams()) as $stream) {
            $out[$stream] = self::sync($stream);
        }

        return $out;
    }

    /** Syncs when the last sync of any stream is older than $minutes. */
    public static function syncIfStale(int $minutes = 15): void
    {
        $st = self::status();
        foreach (array_keys(self::streams()) as $stream) {
            $at = $st[$stream]['at'] ?? null;
            if (! $at || \Carbon\Carbon::parse($at)->lt(now()->subMinutes($minutes))) {
                self::sync($stream);
            }
        }
    }

    /** @return array{0: int|null, 1: string|null} how many are inactive now, and the error if it failed */
    public static function sync(string $stream): array
    {
        $token = self::token();
        $error = null;
        $count = null;
        if (! $token) {
            $error = 'No Postmark server token: set POSTMARK_SERVER_TOKEN in the server .env.';
        } else {
            try {
                $res = Http::withHeaders(['X-Postmark-Server-Token' => $token, 'Accept' => 'application/json'])
                    ->timeout(30)
                    ->get('https://api.postmarkapp.com/message-streams/' . rawurlencode($stream) . '/suppressions/dump');
                if (! $res->successful()) {
                    $error = 'Postmark said ' . $res->status() . ': ' . mb_substr((string) ($res->json('Message') ?? $res->body()), 0, 200);
                } else {
                    $count = self::store($stream, (array) ($res->json('Suppressions') ?? []));
                }
            } catch (\Throwable $e) {
                $error = 'Could not reach Postmark: ' . mb_substr($e->getMessage(), 0, 200);
            }
        }

        if ($error) Log::warning('[Postmark] Inactive list sync failed', ['stream' => $stream, 'error' => $error]);
        $st = self::status();
        $st[$stream] = $error
            ? array_merge($st[$stream] ?? [], ['error' => $error, 'tried_at' => now()->toIso8601String()])
            : ['at' => now()->toIso8601String(), 'count' => $count, 'error' => null];
        PlatformSettings::current();
        DB::table('platform_settings')->where('id', 1)->update(['postmark_inactive_sync' => json_encode($st)]);

        return [$count, $error];
    }

    /** Replaces the stream's rows with what Postmark sent. Returns the count. */
    private static function store(string $stream, array $list): int
    {
        $now = now();
        $rows = [];
        foreach ($list as $s) {
            $email = mb_strtolower(trim((string) ($s['EmailAddress'] ?? '')));
            if ($email === '' || ! str_contains($email, '@')) continue;
            $rows[$email] = [
                'stream' => $stream, 'email' => mb_substr($email, 0, 191),
                'reason' => mb_substr((string) ($s['SuppressionReason'] ?? ''), 0, 32) ?: null,
                'origin' => mb_substr((string) ($s['Origin'] ?? ''), 0, 32) ?: null,
                'suppressed_at' => ! empty($s['CreatedAt']) ? \Carbon\Carbon::parse($s['CreatedAt'])->utc() : null,
                'synced_at' => $now,
            ];
        }
        DB::transaction(function () use ($stream, $rows, $now) {
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('postmark_suppressions')->upsert($chunk, ['stream', 'email'], ['reason', 'origin', 'suppressed_at', 'synced_at']);
            }
            // reactivated in Postmark since the last sync
            DB::table('postmark_suppressions')->where('stream', $stream)->where('synced_at', '<', $now)->delete();
        });

        return count($rows);
    }
}
