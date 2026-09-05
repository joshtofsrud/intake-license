<?php
// MARKER-SALE-DELETE-STOCK

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sales deleted before MARKER-SALE-DELETE-STOCK took their stock with them:
 * the units stayed deducted and the outgoing movement rows still reference a
 * sale that no longer exists. This finds those movements and, with --fix,
 * writes the counter-movement that should have happened at delete time.
 *
 * Dry run by default. A stock correction is not something to discover after
 * the fact, so the default is to print and change nothing.
 */
class ReconcileOrphanSaleMovements extends Command
{
    protected $signature = 'intake:reconcile-orphan-sale-movements
                            {--tenant= : limit to one tenant id or subdomain}
                            {--fix : actually write the corrections}';

    protected $description = 'Find stock movements whose sale no longer exists, and optionally put the stock back';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), function ($q) {
                $t = $this->option('tenant');
                $q->where('id', $t)->orWhere('subdomain', $t);
            })
            ->get();

        $grand = 0;

        foreach ($tenants as $tenant) {
            $rows = DB::table('tenant_inventory_movements as m')
                ->leftJoin('tenant_sales as s', 's.id', '=', 'm.reference_id')
                ->where('m.tenant_id', $tenant->id)
                ->where('m.reference_type', 'sale')
                ->whereNotNull('m.reference_id')
                ->whereNull('s.id')
                ->where('m.quantity_delta', '<', 0)
                ->get(['m.id', 'm.inventory_item_id', 'm.location_id', 'm.quantity_delta', 'm.created_at', 'm.reference_id']);

            if ($rows->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->info($tenant->subdomain . ' — ' . $rows->count() . ' orphaned sale movement(s)');

            $names = DB::table('tenant_inventory_items')
                ->whereIn('id', $rows->pluck('inventory_item_id')->filter()->unique())
                ->pluck('name', 'id');

            foreach ($rows as $r) {
                $this->line(sprintf(
                    '  %s  %+d  %s',
                    \Carbon\Carbon::parse($r->created_at)->format('Y-m-d'),
                    -$r->quantity_delta,
                    $names[$r->inventory_item_id] ?? '(item deleted)'
                ));
            }

            $grand += $rows->count();

            if (! $fix) {
                continue;
            }

            $inventory = app(\App\Services\Pos\InventoryService::class);
            $done = 0;

            foreach ($rows as $r) {
                $item = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
                    ->find($r->inventory_item_id);
                $location = \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)
                    ->find($r->location_id);

                if (! $item || ! $location) {
                    $this->warn('  skipped one — item or location no longer exists');
                    continue;
                }

                $inventory->incrementStock(
                    $tenant, $item, $location, (int) abs($r->quantity_delta),
                    'sale', $r->reference_id, null, null, 'return',
                    'Reconciled: sale deleted without returning stock'
                );
                $done++;
            }

            $this->info('  corrected ' . $done);
        }

        if ($grand === 0) {
            $this->info('Nothing orphaned — every sale movement still has its sale.');
        } elseif (! $fix) {
            $this->newLine();
            $this->warn($grand . ' movement(s) would be corrected. Re-run with --fix to write them.');
        }

        return self::SUCCESS;
    }
}
