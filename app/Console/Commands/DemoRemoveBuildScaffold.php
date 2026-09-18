<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-DEMO-BUILD-CLEANUP — remove a leftover demo build scaffold.
 *
 * Dry run by default. It refuses to touch anything that isn't obviously
 * scaffolding: the tenant must be flagged is_demo AND must not be the tenant
 * the demo manifest points at, so the live demo can never be the one deleted.
 */
class DemoRemoveBuildScaffold extends Command
{
    protected $signature = 'demo:remove-build-scaffold
                            {--subdomain=demo-building : Which scaffold to remove}
                            {--apply : Actually delete it}';

    protected $description = 'Remove a leftover demo build scaffold tenant.';

    public function handle(): int
    {
        $sub = (string) $this->option('subdomain');

        $tenant = Tenant::where('subdomain', $sub)->first();
        if (! $tenant) {
            $this->info("No tenant with subdomain '{$sub}'. Nothing to do.");
            return self::SUCCESS;
        }

        if (! $tenant->is_demo) {
            $this->error("'{$sub}' is not flagged is_demo. Refusing — this looks like a real tenant.");
            return self::FAILURE;
        }

        // Never delete whatever the manifest restores from.
        foreach (Storage::disk('local')->directories('demo') as $dir) {
            $path = $dir . '/manifest.json';
            if (! Storage::disk('local')->exists($path)) {
                continue;
            }
            $manifest = json_decode(Storage::disk('local')->get($path), true);
            if (($manifest['tenant_id'] ?? null) === $tenant->id) {
                $this->error("'{$sub}' is the tenant a demo manifest restores from. Refusing.");
                return self::FAILURE;
            }
        }

        $this->line("Scaffold: {$tenant->name} ({$tenant->subdomain}) created {$tenant->created_at}");

        if (! $this->option('apply')) {
            $this->info('DRY RUN — nothing deleted. Re-run with --apply to remove it.');
            return self::SUCCESS;
        }

        $this->purge($tenant);
        $this->info("Deleted {$sub}.");

        return self::SUCCESS;
    }

    /**
     * MARKER-DEMO-SCAFFOLD-PURGE — remove a tenant and everything it owns.
     *
     * A tenant row cannot be deleted on its own: several child tables carry
     * ON DELETE RESTRICT, which is what made forceDelete() fail. This mirrors
     * what DemoBuildTemplate already does when it clears a previous demo —
     * clear every tenant-scoped table with checks off, then the tenant row.
     *
     * The FK checks are restored in a finally, so a failure halfway through
     * cannot leave this connection running without them.
     */
    protected function purge(Tenant $tenant): void
    {
        $db = \Illuminate\Support\Facades\DB::getDatabaseName();

        $tables = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT TABLE_NAME t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'",
            [$db]
        ))->pluck('t')->all();

        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $removed = 0;
            foreach ($tables as $t) {
                if ($t === 'tenants') {
                    continue;
                }
                $removed += \Illuminate\Support\Facades\DB::table($t)
                    ->where('tenant_id', $tenant->id)
                    ->delete();
            }

            \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenant->id)->delete();

            \Illuminate\Support\Facades\Storage::disk('public')
                ->deleteDirectory('tenants/' . $tenant->id);

            $this->line("Cleared {$removed} rows across " . count($tables) . ' tenant-scoped tables.');
        } finally {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
