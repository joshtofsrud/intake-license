<?php
// MARKER-PATCH-622 — build per-tenant search vocabulary from visible items.
// Words from names, subtitles, brands, SKUs → tenant_search_terms with soundex
// + frequency. Nightly (catalogs change via distributor sync) and on demand.

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantSearchTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildSearchTerms extends Command
{
    protected $signature = 'search:build-terms {--tenant= : only this tenant id}';
    protected $description = 'Rebuild the shop-search typo-correction vocabulary per tenant.';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->where('is_active', true)
            ->get(['id']);

        foreach ($tenants as $tenant) {
            $freq = [];

            TenantInventoryItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('show_online', true)
                ->with('distributorCatalog:id,manufacturer')
                ->select(['id', 'tenant_id', 'name', 'display_subtitle', 'sku', 'distributor_catalog_id'])
                ->chunkById(500, function ($items) use (&$freq) {
                    foreach ($items as $i) {
                        $text = mb_strtolower(implode(' ', array_filter([
                            $i->name, $i->display_subtitle, $i->sku,
                            $i->distributorCatalog?->manufacturer,
                        ])));
                        foreach (preg_split('/[^a-z0-9]+/', $text) as $w) {
                            if (mb_strlen($w) >= 3 && mb_strlen($w) <= 60 && !ctype_digit($w)) {
                                $freq[$w] = ($freq[$w] ?? 0) + 1;
                            }
                        }
                    }
                });

            // Replace the tenant's vocabulary atomically-enough: delete + bulk insert.
            DB::transaction(function () use ($tenant, $freq) {
                TenantSearchTerm::where('tenant_id', $tenant->id)->delete();
                $rows = [];
                foreach ($freq as $term => $n) {
                    $rows[] = [
                        'id'         => (string) \Illuminate\Support\Str::uuid(),
                        'tenant_id'  => $tenant->id,
                        'term'       => $term,
                        'soundex'    => soundex($term),
                        'freq'       => $n,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($rows) >= 500) { DB::table('tenant_search_terms')->insert($rows); $rows = []; }
                }
                if ($rows) DB::table('tenant_search_terms')->insert($rows);
            });

            $this->info($tenant->id . ': ' . count($freq) . ' terms');
        }

        return self::SUCCESS;
    }
}

