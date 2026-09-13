<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantSpecialOrder;
use App\Support\SpecialOrderCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SO-ORPHANS — cancel special orders whose reason for existing has gone.
 *
 * CANCELS rather than deletes, for the reason the board states: one that
 * reached a vendor may have money against it, and its history is the only
 * record. Cancelled is reversible and auditable.
 *
 * Only touches orders older than the tenant's interval, and only those still
 * open — an order already pulled or cancelled is left alone.
 */
class SpecialOrdersClearOrphansCommand extends Command
{
    protected $signature = 'special-orders:clear-orphans
        {--tenant= : One tenant subdomain, or omit for all}
        {--apply : Cancel them. Without this, only reports}';

    protected $description = 'Cancel special orders whose sale or work order no longer exists.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $total = 0;

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('subdomain', $this->option('tenant')))
            ->get(['id', 'subdomain', 'settings']);

        foreach ($tenants as $tenant) {
            $days = SpecialOrderCleanup::days($tenant);

            if ($days === 0) {
                $this->line("  {$tenant->subdomain}: automatic clearing is off");
                continue;
            }

            $cutoff = now()->subDays($days);

            $open = TenantSpecialOrder::where('tenant_id', $tenant->id)
                ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
                ->where('updated_at', '<', $cutoff)
                ->get();

            $orphans = $open->filter(function ($so) use ($tenant) {
                // A sale or work order that no longer exists is what makes it
                // an orphan. Resolved the same way the board resolves it.
                if ($so->sale_id) {
                    return ! DB::table('tenant_sales')->where('id', $so->sale_id)->exists();
                }

                if ($so->appointment_id) {
                    return ! DB::table('tenant_appointments')->where('id', $so->appointment_id)->exists();
                }

                return false; // manual orders have no parent to lose
            });

            if ($orphans->isEmpty()) {
                continue;
            }

            $this->line("  {$tenant->subdomain}: {$orphans->count()} orphan(s) older than {$days} day(s)");

            if ($apply) {
                TenantSpecialOrder::whereIn('id', $orphans->pluck('id'))
                    ->update(['status' => TenantSpecialOrder::STATUS_CANCELLED, 'updated_at' => now()]);
            }

            $total += $orphans->count();
        }

        $this->newLine();
        $this->info($apply ? "{$total} cancelled." : "{$total} would be cancelled — re-run with --apply.");

        return self::SUCCESS;
    }
}
