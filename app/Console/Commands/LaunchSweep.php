<?php

namespace App\Console\Commands;

use App\Support\Launch\Launch;
use Illuminate\Console\Command;

/** MARKER-LAUNCH-CHECKLIST: re-check live shops, pause and alert on a broken required step, lift when fixed, remind shops in preview. */
class LaunchSweep extends Command
{
    protected $signature = 'launch:sweep';
    protected $description = 'Re-check launch checklists: alert and pause on broken steps, lift fixed pauses, remind shops in preview';

    public function handle(): int
    {
        $n = Launch::sweep();
        $this->info(sprintf('Checked %d · newly broken %d · paused %d · reopened %d · reminders %d',
            $n['checked'], $n['broken'], $n['paused'], $n['lifted'], $n['reminded']));
        return self::SUCCESS;
    }
}
