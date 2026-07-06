<?php
// MARKER-PATCH-574

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

        $stale = TenantOrder::query()
            ->where('status', TenantOrder::STATUS_PENDING_PAYMENT)
            ->whereNull('sale_id')
            ->where('updated_at', '<', now()->subHours(24));

        if ($this->option('dry-run')) {
            $this->info("Would abandon: {$carts->count()} idle carts, {$stale->count()} stale pending-payment orders.");
            return self::SUCCESS;
        }

        $n1 = $carts->update(['status' => TenantOrder::STATUS_ABANDONED]);
        $n2 = $stale->update(['status' => TenantOrder::STATUS_ABANDONED]);

        $this->info("Abandoned {$n1} idle carts and {$n2} stale pending-payment orders.");
        return self::SUCCESS;
    }
}
