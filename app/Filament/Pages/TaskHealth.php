<?php

namespace App\Filament\Pages;

use App\Models\ScheduledTaskRun;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-TASK-HEALTH — what ran, what failed, what is overdue.
 *
 * Leads with problems: with thirty scheduled commands, a list sorted by next
 * run is a list nobody reads.
 */
class TaskHealth extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Scheduled tasks';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int    $navigationSort  = 25;
    protected static string  $view            = 'filament.pages.task-health';
    protected static ?string $slug            = 'scheduled-tasks';

    /**
     * Commands that change money or delete things. Running these by hand is a
     * decision, not a click — the page says what each one will do.
     */
    private const CONSEQUENCES = [
        'billing:charge-due'      => 'Charges cards for any shop over its threshold.',
        'demo:reset'              => 'Wipes the demo tenant and rebuilds it.',
        'campaigns:process-sends' => 'Sends queued campaign email.',
        'gift-cards:deliver'      => 'Sends gift cards that are due.',
        'appointments:remind'     => 'Emails appointment reminders to customers.',
        'deliveries:remind'       => 'Emails delivery reminders to customers.',
        'bookings:send-reminders' => 'Emails booking reminders to customers.',
        'debug-log:prune'         => 'Deletes old debug logs.',
        'funnel:prune'            => 'Deletes old funnel events.',
        'orders:reap-abandoned'   => 'Cancels abandoned orders.',
        'sales:reap-drafts'       => 'Deletes stale draft sales.',
        'bookings:reap-holds'     => 'Releases held booking slots.',
        'gift-cards:reap-pending' => 'Cancels pending gift cards.',
        'waitlist:expire'         => 'Expires waitlist entries.',
        'addons:expire'           => 'Ends add-ons that have run out.',
    ];

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** One row per command: its last run, and whether it looks healthy. */
    public function tasks(): array
    {
        $last = ScheduledTaskRun::query()
            ->select('command', DB::raw('MAX(started_at) as last_started'))
            ->groupBy('command')->pluck('last_started', 'command');

        $rows = [];

        foreach ($last as $command => $startedAt) {
            $run = ScheduledTaskRun::where('command', $command)
                ->orderByDesc('started_at')->first();

            // Cadence from what actually happened, rather than a threshold per
            // task that I would have to keep in step with the schedule.
            $recent = ScheduledTaskRun::where('command', $command)
                ->orderByDesc('started_at')->limit(6)->pluck('started_at');

            $gaps = [];
            for ($i = 0; $i < $recent->count() - 1; $i++) {
                $gaps[] = $recent[$i]->diffInSeconds($recent[$i + 1]);
            }
            $typical = $gaps ? (int) (array_sum($gaps) / count($gaps)) : null;

            $since   = $run->started_at ? now()->diffInSeconds($run->started_at) : null;
            // Generous: three times its own usual gap, plus a minute of slack,
            // so a busy minute never reads as a failure.
            $overdue = ($typical && $since) ? $since > (($typical * 3) + 60) : false;

            $rows[] = [
                'command'   => $command,
                'last'      => $run->started_at,
                'ok'        => (bool) $run->ok,
                'running'   => $run->finished_at === null,
                'duration'  => $run->duration(),
                'failure'   => $run->failure,
                'manual'    => (bool) $run->manual,
                'overdue'   => $overdue,
                'typical'   => $typical,
                'note'      => self::CONSEQUENCES[$command] ?? null,
                'risky'     => array_key_exists($command, self::CONSEQUENCES),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['overdue'] || ! $b['ok'], $a['command']]
                                <=> [$a['overdue'] || ! $a['ok'], $b['command']]);

        return $rows;
    }

    public function summary(): array
    {
        $rows = $this->tasks();

        return [
            'total'   => count($rows),
            'failing' => count(array_filter($rows, fn ($r) => ! $r['ok'] && ! $r['running'])),
            'overdue' => count(array_filter($rows, fn ($r) => $r['overdue'])),
            'never'   => 0,
        ];
    }

    public function runNow(string $command): void
    {
        // Only commands the scheduler already runs — this is not a shell.
        if (! collect($this->tasks())->pluck('command')->contains($command)) {
            Notification::make()->danger()->title('Unknown task')->send();
            return;
        }

        $row = ScheduledTaskRun::create([
            'command'    => $command,
            'started_at' => now(),
            'manual'     => true,
            'run_by'     => Auth::guard('web')->user()?->email,
        ]);

        $started = microtime(true);

        try {
            Artisan::call($command);
            $row->update([
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ok'          => true,
            ]);
            Notification::make()->success()->title($command . ' finished')
                ->body(mb_substr(trim(Artisan::output()), 0, 300) ?: 'No output.')->send();
        } catch (\Throwable $e) {
            $row->update([
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ok'          => false,
                'failure'     => mb_substr($e->getMessage(), 0, 500),
            ]);
            Notification::make()->danger()->title($command . ' failed')
                ->body(mb_substr($e->getMessage(), 0, 300))->send();
        }
    }
}
