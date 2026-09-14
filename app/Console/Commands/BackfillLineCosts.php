<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-COST-PRECEDENCE — repair sale lines stamped with no cost.
 *
 * Before the precedence fix, an item whose only cost was shop_cost_cents had no
 * effective cost, so every line sold from it recorded cost_cents_snapshot as null
 * or zero. (The inventory movement ledger's own cost_cents_at_time has the same
 * gap; it is not touched here, since sale COGS is what the margin reports read.) Those lines are history and this patch does NOT rewrite them on
 * deploy: reports that have already been read would quietly change underneath
 * you. Run this when you want them corrected.
 *
 * Dry run by default. --apply writes.
 */
class BackfillLineCosts extends Command
{
    protected $signature = 'inventory:backfill-line-costs
                            {--apply : Write the corrected costs (otherwise counts only)}
                            {--tenant= : Limit to one tenant id}';

    protected $description = 'Stamp a cost on sale lines that recorded none, using the item\'s own cost.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->where('is_platform', false)
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched.');
            return self::SUCCESS;
        }

        $this->info($apply ? 'Writing corrected line costs...' : 'DRY RUN — nothing will be written.');

        $grandLines = 0;
        $grandCents = 0;

        foreach ($tenants as $tenant) {
            // Lines with no recorded cost, whose item does carry one.
            $rows = DB::table('tenant_sale_items as li')
                ->join('tenant_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
                ->where('li.tenant_id', $tenant->id)
                ->where(function ($w) {
                    $w->whereNull('li.cost_cents_snapshot')->orWhere('li.cost_cents_snapshot', 0);
                })
                ->whereNotNull('li.inventory_item_id')
                ->whereRaw('COALESCE(i.received_cost_cents, i.shop_cost_cents, i.catalog_cost_cents) IS NOT NULL')
                ->select([
                    'li.id',
                    'li.quantity',
                    DB::raw('COALESCE(i.received_cost_cents, i.shop_cost_cents, i.catalog_cost_cents) as unit_cost'),
                ])
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $cents = $rows->sum(fn ($r) => (int) $r->unit_cost * max(1, (int) $r->quantity));
            $grandLines += $rows->count();
            $grandCents += $cents;

            $this->line(sprintf(
                '  %-28s %5d lines   $%s of cost missing',
                mb_strimwidth($tenant->name, 0, 28),
                $rows->count(),
                number_format($cents / 100, 2)
            ));

            if (! $apply) {
                continue;
            }

            foreach ($rows->chunk(500) as $chunk) {
                DB::transaction(function () use ($chunk) {
                    foreach ($chunk as $r) {
                        DB::table('tenant_sale_items')
                            ->where('id', $r->id)
                            ->update(['cost_cents_snapshot' => (int) $r->unit_cost]);
                    }
                });
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d lines, $%s of cost.',
            $apply ? 'Wrote' : 'Would write',
            $grandLines,
            number_format($grandCents / 100, 2)
        ));

        if (! $apply && $grandLines > 0) {
            $this->comment('Re-run with --apply to write these.');
        }

        return self::SUCCESS;
    }
}
