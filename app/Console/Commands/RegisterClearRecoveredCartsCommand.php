<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use App\Support\DraftCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-HOLD — discard unnamed recovered carts after the tenant's interval.
 *
 * Never touches:
 *   · a HELD cart — someone named it and said they wanted it
 *   · a cart with ANY payment against it — a draft cannot normally hold one
 *     today, but the payment-before-finalise work will change that, and this
 *     command must not become the thing that deletes a customer's money later.
 *     Guarded now rather than when it becomes possible.
 */
class RegisterClearRecoveredCartsCommand extends Command
{
    protected $signature = 'register:clear-recovered
        {--tenant= : One tenant subdomain, or omit for all}
        {--apply : Discard them. Without this, only reports}';

    protected $description = 'Discard unnamed autosaved register carts older than the tenant interval.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $total = 0;

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('subdomain', $this->option('tenant')))
            ->get(['id', 'subdomain', 'settings']);

        foreach ($tenants as $tenant) {
            $days = DraftCleanup::days($tenant);

            if ($days === 0) {
                $this->line("  {$tenant->subdomain}: automatic clearing is off");
                continue;
            }

            $carts = TenantSale::where('tenant_id', $tenant->id)
                ->where('payment_status', 'draft')
                ->whereNull('hold_label')                       // never a held cart
                ->where('updated_at', '<', now()->subDays($days))
                ->get(['id']);

            // Never one with money against it.
            $withMoney = DB::table('tenant_sale_payments')
                ->whereIn('sale_id', $carts->pluck('id'))
                ->distinct()
                ->pluck('sale_id')
                ->all();

            $safe = $carts->pluck('id')->reject(fn ($id) => in_array($id, $withMoney, true))->values();

            if ($safe->isEmpty()) {
                continue;
            }

            $this->line("  {$tenant->subdomain}: {$safe->count()} recovered cart(s) older than {$days} day(s)"
                . (count($withMoney) ? ' · ' . count($withMoney) . ' skipped for having payments' : ''));

            if ($apply) {
                TenantSale::whereIn('id', $safe)->delete();
            }

            $total += $safe->count();
        }

        $this->newLine();
        $this->info($apply ? "{$total} discarded." : "{$total} would be discarded — re-run with --apply.");

        return self::SUCCESS;
    }
}
