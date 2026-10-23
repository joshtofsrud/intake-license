<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Demo\ClassStudioDemo;
use App\Services\Demo\Industries\FitnessData;
use Illuminate\Console\Command;

/**
 * Rebuild the class side of a demo studio around today: timetable, members,
 * passes, bookings, waitlists, check-ins, waivers and sales. Touches nothing
 * outside classes. Asks first unless the tenant is marked as a demo.
 * MARKER-CLASSES-DEMO
 */
class DemoClasses extends Command
{
    protected $signature   = 'demo:classes {subdomain : The demo studio, e.g. fitness} {--weeks-back=12} {--weeks-ahead=4}';
    protected $description = 'Rebuild a demo studio\'s classes around today';

    public function handle(): int
    {
        $tenant = Tenant::where('subdomain', strtolower(trim($this->argument('subdomain'))))->first();
        if (! $tenant) {
            $this->error('No tenant with that subdomain.');
            return self::FAILURE;
        }
        if (! $tenant->is_demo && ! $this->confirm("{$tenant->name} isn't marked as a demo. This replaces ALL of its class data. Carry on?", false)) {
            $this->line('Nothing changed.');
            return self::FAILURE;
        }
        $this->info("Rebuilding classes for {$tenant->name}…");
        (new ClassStudioDemo(new FitnessData(), fn ($m) => $this->line($m)))
            ->seed($tenant, (int) $this->option('weeks-back'), (int) $this->option('weeks-ahead'));
        $this->info('Done.');
        return self::SUCCESS;
    }
}
