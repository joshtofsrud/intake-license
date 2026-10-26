<?php

namespace App\Services\Sales;

use App\Jobs\OutreachAutoSendTick;
use App\Models\SalesEmailTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * MARKER-OUTREACH-AUTO: Email outreach sending on its own, at a set pace.
 *
 * Start takes a snapshot of the page: its filters, a saved template and the
 * landing page, plus the pace — seconds between emails to business
 * addresses, and a separate (usually slower) gap between emails to free email
 * addresses, so Gmail, Outlook and the rest see a trickle from us. Optional
 * daily caps per kind, and a bounce limit.
 *
 * It runs on the server: OutreachAutoSendTick sends whatever is due and
 * re-queues itself for the next one, so closing the page doesn't stop it. The
 * scheduler's outreach:auto-keepalive restarts that chain if a deploy or a
 * worker restart drops it. Every email goes through ProspectOutreach::send(),
 * so unsubscribes, one-email-per-address and the activity log all apply
 * exactly as for Send.
 *
 * It stops on its own when the queue runs out, a daily cap is reached for
 * every kind it's sending, a send fails, or bounces and spam complaints
 * since it started reach the limit. The page shows why.
 */
class OutreachAutoSend
{
    public const TZ = 'America/Los_Angeles';
    public const KINDS = ['business', 'free'];

    public static function state(): array
    {
        $raw = DB::table('platform_settings')->where('id', 1)->value('outreach_auto');
        $s = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($s) ? $s : ['running' => false];
    }

    private static function put(array $s): void
    {
        \App\Models\PlatformSettings::current();
        DB::table('platform_settings')->where('id', 1)->update(['outreach_auto' => json_encode($s)]);
    }

    /** The pace and limits last used, to fill the form. */
    public static function defaults(): array
    {
        $c = self::state()['config'] ?? [];

        return [
            'gap_business' => (int) ($c['gap_business'] ?? 10),
            'gap_free'     => (int) ($c['gap_free'] ?? 60),
            'cap_business' => (int) ($c['cap_business'] ?? 0),
            'cap_free'     => (int) ($c['cap_free'] ?? 0),
            'max_bounces'  => (int) ($c['max_bounces'] ?? 5),
        ];
    }

    /**
     * @param array $filters  OutreachQueue filters (who, industryId, state, stage, sort, mail)
     * @param array $pace     gap_business, gap_free (seconds), cap_business, cap_free (per day, 0 = none), max_bounces (0 = never pause)
     */
    public static function start(array $filters, string $templateId, string $page, array $pace, int $userId, string $userName): array
    {
        if (self::state()['running'] ?? false) throw new InvalidArgumentException('Auto-send is already running. Stop it first.');
        $tpl = $templateId !== '' ? SalesEmailTemplate::find($templateId) : null;
        if (! $tpl) throw new InvalidArgumentException('Pick a saved template: auto-send sends the template as saved, not edits on screen.');
        if (trim((string) $tpl->subject) === '' || trim((string) $tpl->body) === '') throw new InvalidArgumentException('That template needs a subject and a message.');

        $int = fn ($v, $min, $max) => max($min, min($max, (int) $v));
        $config = [
            'filters'      => array_intersect_key($filters, array_flip(['who', 'industryId', 'state', 'stage', 'sort', 'mail'])),
            'template_id'  => (string) $tpl->id,
            'template'     => $tpl->name,
            'page'         => ProspectOutreach::normalizePage($page),
            'gap_business' => $int($pace['gap_business'] ?? 10, 1, 3600),
            'gap_free'     => $int($pace['gap_free'] ?? 60, 1, 3600),
            'cap_business' => $int($pace['cap_business'] ?? 0, 0, 10000),
            'cap_free'     => $int($pace['cap_free'] ?? 0, 0, 10000),
            'max_bounces'  => $int($pace['max_bounces'] ?? 5, 0, 1000),
        ];
        if (! OutreachQueue::filtered($config['filters'])->exists()) throw new InvalidArgumentException('The queue is empty for these filters.');

        $s = [
            'running' => true, 'run_id' => (string) Str::uuid(), 'started_at' => now()->toIso8601String(),
            'started_by' => $userId, 'started_by_name' => $userName, 'config' => $config,
            'sent' => ['business' => 0, 'free' => 0], 'skipped' => 0,
            'day' => now(self::TZ)->toDateString(), 'day_sent' => ['business' => 0, 'free' => 0],
            'last' => ['business' => null, 'free' => null], 'next_at' => now()->toIso8601String(),
            'last_tick_at' => null, 'last_sent' => null, 'stopped_at' => null, 'stopped_reason' => null,
        ];
        self::put($s);
        debug_log()->audit('outreach.auto_started', "Auto-send started by {$userName}: template {$tpl->name}, every {$config['gap_business']}s (free email every {$config['gap_free']}s)", null);
        OutreachAutoSendTick::dispatch($s['run_id']);

        return $s;
    }

    public static function stop(string $reason): void
    {
        $s = self::state();
        if (! ($s['running'] ?? false)) return;
        $s['running'] = false;
        $s['stopped_at'] = now()->toIso8601String();
        $s['stopped_reason'] = $reason;
        self::put($s);
        debug_log()->audit('outreach.auto_stopped', 'Auto-send stopped: ' . $reason, null);
    }

    /** Bounces and spam complaints on addresses this run emailed. */
    public static function bounces(array $s): int
    {
        if (empty($s['started_at'])) return 0;

        return (int) DB::table('sales_outreach_sends as o')
            ->join('platform_email_optouts as x', DB::raw('x.email'), '=', DB::raw('LOWER(o.email)'))
            ->where('o.created_at', '>=', \Carbon\Carbon::parse($s['started_at']))
            ->whereIn('x.kind', ['bounce', 'complaint'])
            ->where('x.updated_at', '>=', \Carbon\Carbon::parse($s['started_at']))
            ->distinct()->count('o.email');
    }

    /** Which kinds this run sends: both, or just the one the mail filter picked. */
    private static function kinds(array $config): array
    {
        $mail = $config['filters']['mail'] ?? 'any';

        return in_array($mail, self::KINDS, true) ? [$mail] : self::KINDS;
    }

    /**
     * Sends whatever is due. Returns seconds until the next check, or null
     * when it has stopped (or this tick belongs to an older run).
     */
    public static function tick(string $runId): ?int
    {
        $lock = Cache::lock('outreach-auto-tick', 120);
        if (! $lock->get()) return 5; // another tick is mid-send
        try {
            $s = self::state();
            if (! ($s['running'] ?? false) || ($s['run_id'] ?? null) !== $runId) return null;
            $c = $s['config'];
            $s['last_tick_at'] = now()->toIso8601String();

            if ($c['max_bounces'] > 0 && ($b = self::bounces($s)) >= $c['max_bounces']) {
                self::put($s);
                self::stop("Paused after {$b} bounces or spam complaints since it started. Check Email health before starting again.");
                return null;
            }

            $today = now(self::TZ)->toDateString();
            if (($s['day'] ?? null) !== $today) {
                $s['day'] = $today;
                $s['day_sent'] = ['business' => 0, 'free' => 0];
            }

            $tpl = SalesEmailTemplate::find($c['template_id']);
            if (! $tpl) {
                self::put($s);
                self::stop('The template "' . $c['template'] . '" was deleted.');
                return null;
            }

            $open = []; // kinds that still have shops and room under their cap
            foreach (self::kinds($c) as $kind) {
                $cap = $c['cap_' . $kind];
                if ($cap > 0 && $s['day_sent'][$kind] >= $cap) continue;
                $last = $s['last'][$kind] ? \Carbon\Carbon::parse($s['last'][$kind]) : null;
                if ($last && $last->copy()->addSeconds($c['gap_' . $kind])->isFuture()) {
                    $open[] = $kind;
                    continue;
                }
                $p = OutreachQueue::ordered(['mail' => $kind] + $c['filters'])->first();
                if (! $p) continue;
                $open[] = $kind;

                [$ok, $msg] = ProspectOutreach::send($p, (string) $tpl->subject, (string) $tpl->body, $c['page'], (string) $tpl->id, (int) $s['started_by'], true);
                if ($ok) {
                    $s['sent'][$kind]++;
                    $s['day_sent'][$kind]++;
                    $s['last'][$kind] = now()->toIso8601String();
                    $s['last_sent'] = ['shop' => $p->shop, 'email' => mb_strtolower(trim((string) $p->email)), 'at' => now()->toIso8601String(), 'kind' => $kind];
                } elseif (self::addressProblem($msg)) {
                    // this address can't have it (just emailed, unsubscribed, no address): move on
                    $p->forceFill(['email_skipped_at' => now()])->save();
                    $s['skipped']++;
                } else {
                    self::put($s);
                    self::stop('A send failed: ' . $msg);
                    return null;
                }
            }

            if (! $open) {
                $capped = collect(self::kinds($c))->filter(fn ($k) => $c['cap_' . $k] > 0 && $s['day_sent'][$k] >= $c['cap_' . $k])->isNotEmpty();
                self::put($s);
                self::stop($capped ? "Today's cap is reached and nothing else is left to send." : 'The queue is empty for these filters.');
                return null;
            }

            // next check: the soonest gap among the kinds still sending
            $waits = [];
            foreach ($open as $kind) {
                $last = $s['last'][$kind] ? \Carbon\Carbon::parse($s['last'][$kind]) : null;
                $waits[] = $last ? max(1, (int) ceil(now()->diffInSeconds($last->copy()->addSeconds($c['gap_' . $kind]), false))) : 1;
            }
            $wait = max(1, min(300, min($waits)));
            $s['next_at'] = now()->addSeconds($wait)->toIso8601String();
            self::save($s, $runId);

            return $wait;
        } finally {
            $lock->release();
        }
    }

    /** Saves progress without undoing a Stop pressed while this tick was sending. */
    private static function save(array $s, string $runId): void
    {
        $now = self::state();
        if (! ($now['running'] ?? false) || ($now['run_id'] ?? null) !== $runId) {
            foreach (['running', 'stopped_at', 'stopped_reason', 'run_id'] as $k) $s[$k] = $now[$k] ?? null;
        }
        self::put($s);
    }

    private static function addressProblem(string $msg): bool
    {
        return str_contains($msg, 'marked inactive') || str_contains($msg, 'unsubscribed') || str_contains($msg, 'already had an outreach email') || str_contains($msg, 'Add an email address');
    }

    /** For the scheduler: re-queue the chain if it was lost (deploy, worker restart). */
    public static function keepAlive(): void
    {
        $s = self::state();
        if (! ($s['running'] ?? false)) return;
        $last = $s['last_tick_at'] ?? $s['started_at'] ?? null;
        $next = isset($s['next_at']) ? \Carbon\Carbon::parse($s['next_at']) : null;
        // overdue by more than two minutes past when it meant to run next
        if (! $last || ($next && $next->copy()->addMinutes(2)->isPast())) {
            OutreachAutoSendTick::dispatch($s['run_id']);
        }
    }
}
