<?php
// MARKER-DUP-MERGE

namespace App\Console\Commands;

use App\Services\Inventory\DuplicateItemFinder;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly refresh of every shop's duplicate groups (Inventory › Duplicates). */
class FindDuplicateItems extends Command
{
    protected $signature = 'inventory:find-duplicates {--tenant= : one shop, by subdomain}';

    protected $description = 'Find inventory items that are the same product (shared barcode, or brand + part number)';

    public function handle(DuplicateItemFinder $finder): int
    {
        $tenants = DB::table('tenants')
            ->when($this->option('tenant'), fn ($q, $s) => $q->where('subdomain', $s))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('tenant_inventory_items')->whereColumn('tenant_inventory_items.tenant_id', 'tenants.id'))
            ->pluck('subdomain', 'id');

        foreach ($tenants as $id => $sub) {
            try {
                $r = $finder->find($id);
                $this->line(sprintf('  %-20s ready %d · needs a look %d · cleared %d', $sub, $r['ready'], $r['review'], $r['cleared']));
            } catch (\Throwable $e) {
                $this->error("  {$sub}: {$e->getMessage()}");
                JobFailureReporter::report(self::class, 'Finding duplicate items failed', $e, [], $id);
            }
        }

        return self::SUCCESS;
    }
}
