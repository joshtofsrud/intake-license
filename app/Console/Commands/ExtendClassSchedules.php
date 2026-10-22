<?php

namespace App\Console\Commands;

use App\Services\ClassScheduleService;
use Illuminate\Console\Command;

/** Keep repeating classes created as far ahead as each studio's window. MARKER-CLASSES-P7 */
class ExtendClassSchedules extends Command
{
    protected $signature   = 'classes:extend-schedules';
    protected $description = 'Create upcoming classes for repeating schedules';

    public function handle(ClassScheduleService $svc): int
    {
        $this->info('Created ' . $svc->extendAll() . ' classes.');
        return self::SUCCESS;
    }
}
