<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantOrder;
use Illuminate\Console\Command;

/**
 * Wave 6 — abandoned-cart hygiene. Idle carts (no touch in 48h) and stale
 * pending_payment orders (24h with no sale — the PI died or was never
 * confirmed) roll to `abandoned`, keeping the orders table honest and
 * giving the future recovery email a clean population to draw from.
 */
class OrdersReapAbandonedCommand extends Command
{
    protected $signature = 'orders:reap-abandoned {--dry-run : Count without writing}';
    protected $description = 'Mark idle carts and stale unpaid orders as abandoned';

    public function handle(): int
    {
        $carts = TenantOrder::query()
            ->where('status', TenantOrder::STATUS_CART)
            ->where('updated_at', '<', now()->subHours(48));

        // MARKER-ECOM-SETTINGS: each shop sets how long an unpaid order is held
        // (E-commerce > Payments). 24 hours unless they changed it.
        $staleFor = function () {
            $ids = TenantOrder::query()
                ->where('status', TenantOrder::STATUS_PENDING_PAYMENT)
                ->whereNull('sale_id')
                ->distinct()->pluck('tenant_id');
            $queries = [];
            foreach ($ids as $tid) {
                $hours = max(1, (int) (\App\Models\Tenant::find($tid)?->settings['storefront']['hold_hours'] ?? 24));
                $queries[] = TenantOrder::query()
                    ->where('tenant_id', $tid)
                    ->where('status', TenantOrder::STATUS_PENDING_PAYMENT)
                    ->whereNull('sale_id')
                    ->where('updated_at', '<', now()->subHours($hours));
            }
            return $queries;
        };
        $staleQs = $staleFor();

        if ($this->option('dry-run')) {
            $n = array_sum(array_map(fn ($q) => $q->count(), $staleQs));
            $this->info("Would abandon: {$carts->count()} idle carts, {$n} stale pending-payment orders.");
            return self::SUCCESS;
        }

        $n1 = $carts->update(['status' => TenantOrder::STATUS_ABANDONED]);
        $n2 = array_sum(array_map(fn ($q) => $q->update(['status' => TenantOrder::STATUS_ABANDONED]), $staleQs));

        $this->info("Abandoned {$n1} idle carts and {$n2} stale pending-payment orders.");
        return self::SUCCESS;
    }
}
