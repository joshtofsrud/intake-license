<?php
// MARKER-PATCH-158-G13

namespace App\Console\Commands;

use Database\Seeders\GroundControlSiteSeeder;
use Illuminate\Console\Command;

/**
 * Inject the Ground Control marketing site into a tenant.
 *
 *   php artisan site:seed-ground-control <tenant-id-or-subdomain>
 *
 * Resolves either a UUID or a subdomain. Wipes the tenant's existing
 * marketing pages + nav items and reseeds from the GC brand kit.
 */
class SeedGroundControlSite extends Command
{
    protected $signature = 'site:seed-ground-control {tenant : Tenant UUID or subdomain}';
    protected $description = 'Inject the Ground Control marketing site into a tenant (pages + sections + nav)';

    public function handle(): int
    {
        $arg = $this->argument('tenant');

        $tenant = \App\Models\Tenant::where('id', $arg)
            ->orWhere('subdomain', $arg)
            ->first();

        if (! $tenant) {
            $this->error("No tenant found with id or subdomain: {$arg}");
            return self::FAILURE;
        }

        if (! $this->confirm("This will WIPE and reseed marketing pages + nav for '{$tenant->name}' ({$tenant->subdomain}). Continue?", false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $seeder = new GroundControlSiteSeeder($tenant->id);
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
