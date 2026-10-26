<?php

namespace App\Console\Commands;

use App\Support\QueueWatchdog;
use Illuminate\Console\Command;

/** MARKER-QUEUE-WATCHDOG: see App\Support\QueueWatchdog. Scheduled every five minutes. */
class QueueWatchdogCommand extends Command
{
    protected $signature = 'queue:watchdog';
    protected $description = 'Queue a test job and alert if background jobs have stopped running';

    public function handle(): int
    {
        $state = QueueWatchdog::run();
        $s = QueueWatchdog::status();
        $this->line('Queue: ' . $state . ' · last job run ' . ($s['heartbeat'] ? $s['heartbeat']->diffForHumans() : 'never') . ' · waiting ' . ($s['waiting'] ?? '?'));

        return self::SUCCESS;
    }
}
