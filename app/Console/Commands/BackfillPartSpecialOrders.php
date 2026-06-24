<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantAppointmentPart;
use App\Services\Tenant\SpecialOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfills 'needed' special orders for inventory parts that already sit on
 * OPEN work orders (pending / confirmed / in_progress) and are flagged
 * is_special_order but don't yet have a linked SO. One-time after patch-419;
 * idempotent (skips lines that already have special_order_id), and scoped so
 * it never touches completed/cancelled history.
 */
class BackfillPartSpecialOrders extends Command
{
    protected $signature = 'intake:backfill-part-special-orders {--dry-run : list what would be created, write nothing} {--tenant= : limit to one tenant id}';

    protected $description = 'Create needed special orders for existing inventory parts on open work orders.';

    public function handle(SpecialOrderService $service): int
    {
        $openStatuses = ['pending', 'confirmed', 'in_progress'];
        $tenant = $this->option('tenant');

        $base = TenantAppointmentPart::query()
            ->whereNotNull('inventory_item_id')
            ->whereNull('special_order_id')
            ->where('is_special_order', true)
            ->whereHas('appointment', function ($a) use ($openStatuses, $tenant) {
                $a->whereIn('status', $openStatuses);
                if ($tenant) {
                    $a->where('tenant_id', $tenant);
                }
            });

        $total = (clone $base)->count();
        $this->info("Found {$total} part line(s) on open work orders needing a special order.");
        if ($total === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            (clone $base)->with('appointment')->limit(100)->get()->each(function ($p) {
                $this->line(sprintf('  - %s x%d  (appt %s)', $p->item_name_snapshot, $p->quantity, $p->appointment_id));
            });
            $this->warn('Dry run — nothing written. Re-run without --dry-run to create.');
            return self::SUCCESS;
        }

        $created = 0;
        (clone $base)->chunkById(100, function ($parts) use ($service, &$created) {
            foreach ($parts as $part) {
                try {
                    $service->syncForAppointmentPart($part, null);
                    if ($part->fresh()->special_order_id) {
                        $created++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('backfill-part-special-orders: '.$part->id.' — '.$e->getMessage());
                    $this->error('  failed: '.$part->item_name_snapshot.' ('.$e->getMessage().')');
                }
            }
        });

        $this->info("Created {$created} special order(s).");
        return self::SUCCESS;
    }
}
