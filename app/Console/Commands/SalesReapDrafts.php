<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-REG-SETTINGS — reap stale register drafts and (optionally) quotes.
 *
 * Per tenant, driven by settings:
 *   register_draft_retention_days  (0 = keep forever, the default)
 *   register_quote_retention_days  (0 = keep forever, the default)
 *
 * Discards go through SaleService::discardDraft — the same path as the
 * staff Discard button — so un-placed special orders are retracted and
 * the semantics can never drift from the manual action.
 *
 * NEVER touched: drafts with a ledger payment, and drafts linked to an
 * appointment (the appointment tray parks its cart as a draft).
 */
class SalesReapDrafts extends Command
{
    protected $signature = 'sales:reap-drafts {--dry-run : Report what would be discarded without discarding}';
    protected $description = 'Discard register drafts (and optionally quotes) older than each tenant\'s retention setting.';

    public function handle(SaleService $sales): int
    {
        $dry = (bool) $this->option('dry-run');
        $totals = ['draft' => 0, 'quote' => 0, 'failed' => 0];

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use ($sales, $dry, &$totals) {
            foreach ($tenants as $tenant) {
                $cfg = (array) ($tenant->settings ?? []);
                foreach (['draft' => 'register_draft_retention_days', 'quote' => 'register_quote_retention_days'] as $status => $key) {
                    $days = (int) ($cfg[$key] ?? 0);
                    if ($days < 1) {
                        continue; // 0 / unset = keep forever
                    }

                    $cutoff = now()->subDays($days);
                    $rows = TenantSale::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('payment_status', $status)
                        ->whereNull('appointment_id')
                        ->whereDoesntHave('payments')
                        ->where('updated_at', '<', $cutoff)
                        ->orderBy('updated_at')
                        ->limit(200) // per tenant per night; backlog drains over runs
                        ->get(['id', 'tenant_id', 'updated_at']);

                    foreach ($rows as $row) {
                        if ($dry) {
                            $this->line("would discard {$status} {$row->id} (tenant {$tenant->id}, idle since {$row->updated_at})");
                            $totals[$status]++;
                            continue;
                        }
                        try {
                            $sales->discardDraft($tenant->id, $row->id);
                            $totals[$status]++;
                        } catch (\Throwable $e) {
                            $totals['failed']++;
                            Log::warning('sales.reap_drafts_failed', [
                                'tenant_id' => $tenant->id,
                                'sale_id'   => $row->id,
                                'status'    => $status,
                                'error'     => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        });

        $verb = $dry ? 'would discard' : 'discarded';
        $this->info("sales:reap-drafts {$verb} {$totals['draft']} drafts, {$totals['quote']} quotes ({$totals['failed']} failed)");

        return self::SUCCESS;
    }
}
