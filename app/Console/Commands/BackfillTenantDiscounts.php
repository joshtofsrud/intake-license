<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBillingDiscount;
use Illuminate\Console\Command;

/**
 * MARKER-BILLING-DISCOUNTS — write down the deals that already exist.
 *
 * Gifted shops are identified by settings.signup_path === 'gift' (set when the
 * account was given rather than bought). Nothing is invented: the command
 * reports what it found and only writes with --write, so a wrong guess costs
 * nothing.
 */
class BackfillTenantDiscounts extends Command
{
    protected $signature   = 'billing:backfill-discounts {--write : actually create the records}';
    protected $description = 'Create discount records for shops that were gifted';

    public function handle(): int
    {
        $found = 0;

        $skippedDemo = 0;

        foreach (Tenant::where('is_platform', false)->get() as $tenant) {
            // MARKER-DEMO-BILLING-SKIP — a demo tenant is rebuilt from a copy
            // and wiped hourly; a discount record on it means nothing.
            if ($tenant->is_demo) {
                $skippedDemo++;
                continue;
            }

            $settings = $tenant->settings ?? [];
            $path     = is_array($settings) ? ($settings['signup_path'] ?? null) : null;
            $giftedAt = is_array($settings) ? ($settings['gifted_at'] ?? null) : null;

            if ($path !== 'gift' && ! $giftedAt) {
                continue;
            }
            if (TenantBillingDiscount::where('tenant_id', $tenant->id)->exists()) {
                $this->line("  {$tenant->name}: already has a discount, skipped");
                continue;
            }

            $start = $giftedAt ? \Carbon\Carbon::parse($giftedAt)->startOfMonth() : now()->startOfMonth();
            $end   = $start->copy()->addYear()->subDay();

            $this->line("  {$tenant->name}: gifted {$start->format('M Y')} → free through {$end->format('M j, Y')}");
            $found++;

            if ($this->option('write')) {
                TenantBillingDiscount::create([
                    'tenant_id'    => $tenant->id,
                    'reason'       => 'Gifted account — no platform charge',
                    'scope'        => 'both',
                    'percent'      => 100,
                    'starts_on'    => $start,
                    'ends_on'      => $end,
                    'created_by'   => 'billing:backfill-discounts',
                ]);
            }
        }

        if ($skippedDemo) {
            $this->line("  ({$skippedDemo} demo tenant(s) skipped — they are never billed.)");
        }

        if (! $found) {
            $this->info('No gifted shops found without a discount record.');
            return self::SUCCESS;
        }

        $this->info($this->option('write')
            ? "{$found} discount(s) created."
            : "{$found} shop(s) would get a discount. Re-run with --write to create them.");

        $this->line('Anything with different terms — a founding-shop year two, a negotiated rate — should be');
        $this->line('added by hand under Platform → Discounts so the reason reads correctly on their statement.');

        return self::SUCCESS;
    }
}
