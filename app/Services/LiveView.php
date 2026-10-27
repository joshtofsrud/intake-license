<?php

namespace App\Services;

use App\Models\LiveViewSession;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Cache;

/**
 * MARKER-LIVE-VIEW: master admin watching one tab of a shop's Intake, with
 * the shop's say-so.
 *
 *   1. Master admin asks (request): one person, or anyone signed in.
 *   2. Their open Intake tabs check every 15 seconds (pending). The first tab
 *      that's asked shows Allow / Decline; nothing is seen until Allow.
 *   3. Allowed, that tab records its own page with rrweb (view only: the
 *      page's layout and changes, never clicks sent back) and posts what
 *      changed about every quarter second (push).
 *   4. Master admin's page reads the same batches over a stream and redraws
 *      them. About a quarter to half a second behind.
 *   5. It ends when either side presses Stop, the tab goes quiet for a
 *      minute (closed), or after 30 minutes.
 *
 * Nothing on screen is kept: batches live in the cache for under an hour and
 * are dropped when the session ends. Passwords, PINs and card fields are
 * masked in the tab before they leave it. The session row is the record of
 * who asked, who allowed it and when it ended; the audit log has the same.
 */
class LiveView
{
    public const ASK_FOR = 120;      // seconds an unanswered request stays open
    public const LASTS = 30;         // minutes a session runs once allowed
    public const QUIET_END = 60;     // seconds without a word from the tab before it counts as closed
    public const POLL = 15;          // seconds between a tab's checks for a request
    public const KEEP = 3600;        // seconds batches may sit in the cache
    public const MAX_BYTES = 12_000_000;

    // ── master admin ────────────────────────────────────────────────

    public static function request(Tenant $tenant, ?string $tenantUserId, ?object $admin): LiveViewSession
    {
        LiveViewSession::where('tenant_id', $tenant->id)->whereIn('status', ['requested', 'active'])->get()
            ->each(fn ($s) => self::end($s, 'replaced by a new request'));

        $name = trim((string) ($admin->name ?? '')) ?: 'Intake support';
        $s = LiveViewSession::create([
            'tenant_id'         => $tenant->id,
            'tenant_user_id'    => $tenantUserId ?: null,
            'requested_by'      => $admin->id ?? null,
            'requested_by_name' => mb_substr($name, 0, 120),
            'status'            => 'requested',
        ]);
        Cache::put(self::askKey($tenant->id), 1, self::ASK_FOR + 5);

        self::audit('live_view.requested', $name . ' asked to see ' . $tenant->name . ($tenantUserId ? ' (' . (TenantUser::find($tenantUserId)?->name ?? 'one person') . ')' : ' (anyone signed in)'), $s);

        return $s;
    }

    /**
     * The session as it stands now. Lapsed ones are closed here, on read,
     * so nothing has to sweep them.
     */
    public static function refresh(LiveViewSession $s): LiveViewSession
    {
        if ($s->status === 'requested' && $s->created_at && $s->created_at->lt(now()->subSeconds(self::ASK_FOR))) {
            self::close($s, 'expired', 'nobody answered');
        } elseif ($s->status === 'active') {
            if ($s->expires_at && $s->expires_at->isPast()) {
                self::end($s, 'time up (' . self::LASTS . ' minutes)');
            } elseif (self::quietFor($s) >= self::QUIET_END) {
                self::end($s, 'their tab closed');
            }
        }

        return $s;
    }

    /** Seconds since the tab last posted. */
    public static function quietFor(LiveViewSession $s): int
    {
        $ping = Cache::get(self::key($s, 'ping'));
        $since = $ping ? (int) $ping : ($s->accepted_at?->getTimestamp() ?? time());

        return max(0, time() - $since);
    }

    /** What the viewer shows under the picture. */
    public static function describe(LiveViewSession $s): array
    {
        $quiet = $s->status === 'active' ? self::quietFor($s) : 0;
        $label = match ($s->status) {
            'requested' => 'Waiting for ' . self::whom($s) . ' to allow it',
            'active'    => 'Live · ' . ($s->accepted_by_name ?: 'their tab') . ($quiet >= 5 ? ' · nothing from their tab for ' . $quiet . ' s' : ''),
            'declined'  => ($s->accepted_by_name ?: 'They') . ' declined',
            'expired'   => 'Nobody answered within ' . (self::ASK_FOR / 60) . ' minutes',
            default     => 'Ended' . ($s->end_reason ? ': ' . $s->end_reason : ''),
        };

        return ['status' => $s->status, 'label' => $label, 'quiet' => $quiet,
            'ends' => $s->status === 'active' && $s->expires_at ? $s->expires_at->getTimestamp() : null];
    }

    public static function whom(LiveViewSession $s): string
    {
        return $s->tenant_user_id ? (TenantUser::find($s->tenant_user_id)?->name ?: 'them') : 'anyone signed in';
    }

    /**
     * The shop's people who have Intake open now (a tab checked in within
     * the last 45 seconds), keyed by id: ['name' => , 'ago' => seconds].
     */
    public static function online(Tenant $tenant): array
    {
        $people = TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $out = [];
        foreach ($people as $p) {
            $at = Cache::get(self::seenKey($tenant->id, $p->id));
            if ($at && time() - (int) $at <= 45) $out[$p->id] = ['name' => $p->name, 'ago' => time() - (int) $at];
        }

        return $out;
    }

    /**
     * Batches after $after, as [number => json]. A viewer that has just
     * opened passes -1 and starts at the latest full snapshot; one that
     * fell behind a newer snapshot jumps to it.
     */
    public static function batches(LiveViewSession $s, int $after, int $max = 40): array
    {
        $last = (int) Cache::get(self::key($s, 'n'), 0);
        $snap = (int) Cache::get(self::key($s, 'snap'), 0);
        if ($snap === 0 || $last <= $after) return [];

        $i = $after < $snap ? $snap : $after + 1;
        $out = [];
        for (; $i <= $last && count($out) < $max; $i++) {
            $b = Cache::get(self::key($s, 'b' . $i));
            if ($b === null) {
                // dropped under a newer snapshot: carry on from that one
                $snap = (int) Cache::get(self::key($s, 'snap'), 0);
                if ($i < $snap) { $i = $snap - 1; continue; }
                continue;
            }
            $out[$i] = $b;
        }

        return $out;
    }

    /** The viewer wants a fresh full picture (it has just opened). */
    public static function wantSnapshot(LiveViewSession $s): void
    {
        Cache::put(self::key($s, 'want'), 1, self::KEEP);
    }

    public static function end(LiveViewSession $s, string $reason, ?string $by = null): void
    {
        if (! $s->isOpen()) return;
        self::close($s, 'ended', $reason);
        self::audit('live_view.ended', 'Live view of ' . ($s->tenant?->name ?? 'a shop') . ' ended: ' . $reason . ($by ? ' (by ' . $by . ')' : ''), $s);
    }

    // ── the shop's tab ──────────────────────────────────────────────

    /** A request waiting for this person, if any. Cheap: one cache read when there is none. */
    public static function pending(Tenant $tenant, TenantUser $user): ?LiveViewSession
    {
        Cache::put(self::seenKey($tenant->id, $user->id), time(), 60);
        if (! Cache::get(self::askKey($tenant->id))) return null;

        $s = LiveViewSession::where('tenant_id', $tenant->id)->where('status', 'requested')
            ->where('created_at', '>=', now()->subSeconds(self::ASK_FOR))
            ->where(fn ($q) => $q->whereNull('tenant_user_id')->orWhere('tenant_user_id', $user->id))
            ->latest()->first();
        if (! $s) Cache::forget(self::askKey($tenant->id));

        return $s;
    }

    /** Allow or decline. False when it's no longer theirs to answer (someone else did, or it lapsed). */
    public static function answer(LiveViewSession $s, TenantUser $user, bool $allow): bool
    {
        if ($s->tenant_id !== $user->tenant_id || ($s->tenant_user_id && $s->tenant_user_id !== $user->id)) return false;
        self::refresh($s);
        $won = LiveViewSession::whereKey($s->id)->where('status', 'requested')->update($allow
            ? ['status' => 'active', 'accepted_by_user_id' => $user->id, 'accepted_by_name' => mb_substr((string) $user->name, 0, 120),
               'accepted_at' => now(), 'expires_at' => now()->addMinutes(self::LASTS), 'updated_at' => now()]
            : ['status' => 'declined', 'accepted_by_user_id' => $user->id, 'accepted_by_name' => mb_substr((string) $user->name, 0, 120),
               'ended_at' => now(), 'end_reason' => 'declined', 'updated_at' => now()]);
        if (! $won) return false;

        Cache::forget(self::askKey($s->tenant_id));
        $s->refresh();
        if ($allow) {
            Cache::put(self::key($s, 'ping'), time(), self::KEEP);
            self::wantSnapshot($s);
        }
        self::audit($allow ? 'live_view.allowed' : 'live_view.declined', $user->name . ($allow ? ' allowed' : ' declined') . ' live view for ' . $s->requested_by_name, $s);

        return true;
    }

    /**
     * One batch of recorded events from the tab. Returns what the tab
     * should do next: ['ok' => , 'want' => take a full snapshot, 'end' => stop].
     */
    public static function push(LiveViewSession $s, TenantUser $user, string $json): array
    {
        self::refresh($s);
        if ($s->status !== 'active' || $s->accepted_by_user_id !== $user->id) {
            return ['ok' => false, 'want' => false, 'end' => true];
        }
        Cache::put(self::key($s, 'ping'), time(), self::KEEP);

        if ($json !== '' && $json !== '[]' && strlen($json) <= self::MAX_BYTES) {
            $events = json_decode($json, true);
            if (is_array($events) && array_is_list($events) && $events) {
                $nKey = self::key($s, 'n');
                Cache::add($nKey, 0, self::KEEP);
                $n = (int) Cache::increment($nKey);
                Cache::put(self::key($s, 'b' . $n), $json, self::KEEP);

                // a full snapshot (type 2) is a new starting point; what came before it can go
                foreach ($events as $e) {
                    if (($e['type'] ?? null) === 2) {
                        $old = (int) Cache::get(self::key($s, 'snap'), 0);
                        Cache::put(self::key($s, 'snap'), $n, self::KEEP);
                        for ($i = max(1, $old); $i < $n; $i++) Cache::forget(self::key($s, 'b' . $i));
                        break;
                    }
                }
            }
        }

        $want = (bool) Cache::pull(self::key($s, 'want'));

        return ['ok' => true, 'want' => $want, 'end' => false];
    }

    // ── internals ───────────────────────────────────────────────────

    private static function close(LiveViewSession $s, string $status, string $reason): void
    {
        $s->forceFill(['status' => $status, 'ended_at' => now(), 'end_reason' => mb_substr($reason, 0, 60)])->save();
        self::forget($s);
    }

    /** Drop everything the tab sent. */
    private static function forget(LiveViewSession $s): void
    {
        $last = (int) Cache::get(self::key($s, 'n'), 0);
        $from = max(1, (int) Cache::get(self::key($s, 'snap'), 1));
        for ($i = $from; $i <= $last; $i++) Cache::forget(self::key($s, 'b' . $i));
        foreach (['n', 'snap', 'want', 'ping'] as $k) Cache::forget(self::key($s, $k));
    }

    private static function key(LiveViewSession $s, string $k): string
    {
        return 'liveview:' . $s->id . ':' . $k;
    }

    private static function askKey(string $tenantId): string
    {
        return 'liveview:ask:' . $tenantId;
    }

    private static function seenKey(string $tenantId, string $userId): string
    {
        return 'liveview:seen:' . $tenantId . ':' . $userId;
    }

    private static function audit(string $event, string $message, LiveViewSession $s): void
    {
        try {
            debug_log()->audit($event, $message, $s->tenant ?? null, ['session' => $s->id]);
        } catch (\Throwable $e) {
            // the log never stops a session
        }
    }
}
