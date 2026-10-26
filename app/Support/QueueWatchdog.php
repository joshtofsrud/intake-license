<?php

namespace App\Support;

use App\Jobs\QueueHeartbeat;
use App\Models\PlatformSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

/**
 * MARKER-QUEUE-WATCHDOG: notices when background jobs have stopped running.
 *
 * Every five minutes the scheduler (a separate process from the workers)
 * runs queue:watchdog, which puts a QueueHeartbeat job on the queue. A worker
 * running it stamps queue_heartbeat_at. queue_ping_at is when the oldest
 * test job still waiting was queued; once a worker answers, the next run
 * starts a fresh one. If a test job has waited STALE_MINUTES unanswered, the
 * queue is down: one email to the dashboard alert address (sent directly,
 * never through the queue it's reporting on), a master-admin inbox alert and
 * a log line. When heartbeats resume, one "back to normal" email.
 *
 * Optionally each run also pings an outside monitor (QUEUE_WATCHDOG_PING_URL,
 * e.g. Healthchecks.io): the plain URL when all is well, URL/fail when the
 * queue is down. If the scheduler or the whole server dies the pings stop and
 * the outside service raises the alarm, which nothing on the server can.
 */
class QueueWatchdog
{
    public const STALE_MINUTES = 10;

    /**
     * Where things stand, without changing anything.
     *
     * @return array{state: string, heartbeat: ?Carbon, ping: ?Carbon, down_since: ?Carbon, waiting: ?int}
     *   state: ok | down | unknown (the watchdog hasn't run yet)
     */
    public static function status(): array
    {
        $row = DB::table('platform_settings')->where('id', 1)->first(['queue_ping_at', 'queue_heartbeat_at', 'queue_down_since']);
        $p = fn ($v) => $v ? Carbon::parse($v, 'UTC') : null;
        $heartbeat = $p($row->queue_heartbeat_at ?? null);
        $ping = $p($row->queue_ping_at ?? null);

        $state = 'unknown';
        if ($ping) {
            $answered = $heartbeat && $heartbeat->gte($ping);
            $state = (! $answered && $ping->lt(now()->subMinutes(self::STALE_MINUTES))) ? 'down' : 'ok';
        }

        return ['state' => $state, 'heartbeat' => $heartbeat, 'ping' => $ping, 'down_since' => $p($row->queue_down_since ?? null), 'waiting' => self::waiting()];
    }

    /** One watchdog run: queue a test job, then alert or recover as needed. Returns the state. */
    public static function run(): string
    {
        PlatformSettings::current(); // makes sure the id = 1 row exists
        $before = self::status();

        // a fresh test job once the last one has been answered (or on the first run)
        $answered = $before['ping'] && $before['heartbeat'] && $before['heartbeat']->gte($before['ping']);
        if (! $before['ping'] || $answered) {
            DB::table('platform_settings')->where('id', 1)->update(['queue_ping_at' => now()]);
        }
        $queued = true;
        try {
            QueueHeartbeat::dispatch();
        } catch (\Throwable $e) {
            $queued = false; // Redis itself is unreachable: the queue can't be working
            Log::error('Queue watchdog could not queue its test job: ' . $e->getMessage());
        }

        $now = self::status();
        $down = $now['state'] === 'down';

        if ($down && ! $now['down_since']) {
            DB::table('platform_settings')->where('id', 1)->update(['queue_down_since' => now()]);
            self::tell(false, $now, $queued);
        } elseif (! $down && $now['down_since'] && $now['state'] === 'ok' && $answered) {
            DB::table('platform_settings')->where('id', 1)->update(['queue_down_since' => null]);
            self::tell(true, $now, $queued);
        }

        self::pingOutside($down);

        return $now['state'];
    }

    /** The down / back-to-normal message: email (direct), master-admin inbox, log. */
    private static function tell(bool $recovered, array $s, bool $queued): void
    {
        $ago = fn (?Carbon $t) => $t ? $t->diffForHumans(['parts' => 2]) . ' (' . $t->toDateTimeString() . ' UTC)' : 'never';
        if ($recovered) {
            $subject = '[Intake] Background jobs are running again';
            $body = "Workers are picking up jobs again.\n\n"
                . 'Down since: ' . $ago($s['down_since']) . "\n"
                . 'Jobs waiting now: ' . ($s['waiting'] ?? 'unknown') . "\n\n"
                . "Anything queued while they were down (emails, syncs, campaign sends) has run now, or is running.";
        } else {
            $subject = '[Intake] Background jobs have stopped';
            $body = "No worker has run a job for over " . self::STALE_MINUTES . " minutes, so queued work (emails, syncs, campaign sends) is waiting.\n\n"
                . 'Last job run: ' . $ago($s['heartbeat']) . "\n"
                . 'Jobs waiting: ' . ($s['waiting'] ?? 'unknown (Redis could not be read)') . "\n"
                . ($queued ? '' : "The watchdog could not reach Redis to queue its test job.\n")
                . "\nOn the server:\n"
                . "supervisorctl status\n"
                . "tail -n 40 /var/www/intake/storage/logs/worker.log\n\n"
                . "If a worker shows FATAL, it crashed on every start and supervisor gave up. After fixing the cause:\n"
                . "supervisorctl start \"intake-worker:*\"\n\n"
                . "You'll get one more email when jobs are running again.";
        }

        Log::log($recovered ? 'info' : 'error', $subject, ['heartbeat' => (string) $s['heartbeat'], 'waiting' => $s['waiting']]);
        \App\Support\PlatformInbox::alert(['subject' => $subject, 'body' => $body, 'meta' => ['watchdog' => 'queue', 'recovered' => $recovered]]);

        try {
            $to = PlatformSettings::current()->alert_500_email ?: null;
            if ($to) {
                Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
            }
        } catch (\Throwable $e) {
            Log::error('Queue watchdog email failed: ' . $e->getMessage());
        }
    }

    /** The outside dead-man's switch, if one is set up. Never throws. */
    private static function pingOutside(bool $down): void
    {
        $url = rtrim((string) config('services.queue_watchdog.ping_url'), '/');
        if ($url === '') return;
        try {
            Http::timeout(5)->get($down ? $url . '/fail' : $url);
        } catch (\Throwable $e) {
            Log::warning('Queue watchdog outside ping failed: ' . $e->getMessage());
        }
    }

    /** Jobs waiting on the main queue, or null if Redis can't be read. */
    public static function waiting(): ?int
    {
        try {
            return (int) Redis::llen('queues:default');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
