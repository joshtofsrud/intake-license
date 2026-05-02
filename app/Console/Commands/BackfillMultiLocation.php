<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent backfill for the multi-location migration.
 *
 * Creates a default "Main" location for every tenant that doesn't have one,
 * then stamps location_id on every existing capacity rule, inventory movement,
 * and receive shipment.
 *
 * Safe to re-run. Each step checks for existing data before writing.
 *
 * Run after migrations 10-14 land in production. After this completes
 * successfully, the follow-up migration to enforce NOT NULL on location_id
 * columns can be written and shipped.
 */
class BackfillMultiLocation extends Command
{
    protected $signature = 'intake:backfill-multi-location {--dry-run : Show what would change without writing}';

    protected $description = 'Backfill default location for all tenants and stamp existing rows.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN — no changes will be written.' : 'Running backfill.');

        $stats = [
            'locations_created' => 0,
            'capacity_rules_updated' => 0,
            'movements_updated' => 0,
            'shipments_updated' => 0,
            'item_locations_created' => 0,
        ];

        $tenants = Tenant::all();
        $this->info("Processing {$tenants->count()} tenants...");

        foreach ($tenants as $tenant) {
            DB::transaction(function () use ($tenant, $dryRun, &$stats) {
                // Step 1: ensure default location exists
                $defaultLocation = DB::table('tenant_locations')
                    ->where('tenant_id', $tenant->id)
                    ->where('is_default', true)
                    ->first();

                if (!$defaultLocation) {
                    if (!$dryRun) {
                        $locationId = (string) Str::uuid();
                        DB::table('tenant_locations')->insert([
                            'id' => $locationId,
                            'tenant_id' => $tenant->id,
                            'name' => 'Main',
                            'slug' => 'main',
                            'is_default' => true,
                            'is_active' => true,
                            'sort_order' => 0,
                            'timezone' => $tenant->timezone ?? null,
                            'country' => 'US',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $defaultLocation = (object) ['id' => $locationId];
                    } else {
                        $defaultLocation = (object) ['id' => 'DRYRUN-' . $tenant->id];
                    }
                    $stats['locations_created']++;
                }

                // Step 2: stamp existing capacity rules
                $updatedRules = DB::table('tenant_capacity_rules')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('location_id');

                if (!$dryRun) {
                    $stats['capacity_rules_updated'] += $updatedRules->update([
                        'location_id' => $defaultLocation->id,
                    ]);
                } else {
                    $stats['capacity_rules_updated'] += $updatedRules->count();
                }

                // Step 3: stamp existing inventory movements
                $updatedMovements = DB::table('tenant_inventory_movements')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('location_id');

                if (!$dryRun) {
                    $stats['movements_updated'] += $updatedMovements->update([
                        'location_id' => $defaultLocation->id,
                    ]);
                } else {
                    $stats['movements_updated'] += $updatedMovements->count();
                }

                // Step 4: stamp existing receive shipments
                $updatedShipments = DB::table('tenant_inventory_receive_shipments')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('location_id');

                if (!$dryRun) {
                    $stats['shipments_updated'] += $updatedShipments->update([
                        'location_id' => $defaultLocation->id,
                    ]);
                } else {
                    $stats['shipments_updated'] += $updatedShipments->count();
                }

                // Step 5: create tenant_inventory_item_locations rows for existing items
                $itemsNeedingLocation = DB::table('tenant_inventory_items')
                    ->where('tenant_id', $tenant->id)
                    ->whereNotExists(function ($q) use ($defaultLocation) {
                        $q->select(DB::raw(1))
                          ->from('tenant_inventory_item_locations')
                          ->whereColumn('tenant_inventory_item_locations.inventory_item_id', 'tenant_inventory_items.id')
                          ->where('tenant_inventory_item_locations.location_id', $defaultLocation->id);
                    })
                    ->get();

                foreach ($itemsNeedingLocation as $item) {
                    if (!$dryRun) {
                        DB::table('tenant_inventory_item_locations')->insert([
                            'id' => (string) Str::uuid(),
                            'tenant_id' => $tenant->id,
                            'inventory_item_id' => $item->id,
                            'location_id' => $defaultLocation->id,
                            'computed_stock_count' => $item->computed_stock_count ?? 0,
                            'shop_reorder_threshold' => $item->shop_reorder_threshold,
                            'shop_reorder_quantity' => $item->shop_reorder_quantity,
                            'shop_bin_location' => $item->shop_bin_location,
                            'is_active' => $item->is_active ?? true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $stats['item_locations_created']++;
                }
            });

            $this->line("  Processed tenant: {$tenant->name} ({$tenant->subdomain})");
        }

        $this->newLine();
        $this->info('Backfill complete.');
        $this->table(
            ['Operation', 'Count'],
            collect($stats)->map(fn($v, $k) => [$k, $v])->values()->toArray()
        );

        if ($dryRun) {
            $this->warn('DRY RUN — no changes were written. Re-run without --dry-run to apply.');
        }

        return Command::SUCCESS;
    }
}
