<?php

namespace App\Console\Commands;

// MARKER-TITLE-RATIO — one-time drain: resolves open title_changed flags whose
// recorded old->new change is below the configured ratio. These are exactly the
// flags the new sync-time gate would never have opened. Dry by default.
// Baselines are not touched here; the next sync advances them silently through
// the same gate.

use App\Models\Tenant\TenantPricingAttentionFlag;
use App\Services\Distributors\TenantDistributorSyncService;
use Illuminate\Console\Command;

class ResolveTrivialTitleFlags extends Command
{
    protected $signature = 'intake:attention-trivial-titles {--tenant= : limit to one tenant id} {--apply : actually resolve (default is a dry count)}';

    protected $description = 'Resolve open title_changed attention flags whose name change is below the configured ratio';

    public function handle(): int
    {
        $threshold = (float) config('distributors.title_change_min_ratio', 0.15);
        $apply = (bool) $this->option('apply');

        $q = TenantPricingAttentionFlag::query()
            ->where('status', 'open')
            ->where('reason', TenantPricingAttentionFlag::REASON_TITLE_CHANGED);
        if ($this->option('tenant')) {
            $q->where('tenant_id', $this->option('tenant'));
        }

        $trivial = 0;
        $kept = 0;
        $q->lazyById(500)->each(function (TenantPricingAttentionFlag $flag) use ($threshold, $apply, &$trivial, &$kept) {
            $d = $flag->detail ?? [];
            $ratio = TenantDistributorSyncService::titleChangeRatio($d['old'] ?? null, $d['new'] ?? null);
            if ($ratio >= $threshold) {
                $kept++;

                return;
            }
            $trivial++;
            if ($apply) {
                $flag->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        });

        $verb = $apply ? 'resolved' : 'would resolve (dry run — pass --apply)';
        $this->info("Threshold {$threshold}: {$verb} {$trivial} trivial title flags; {$kept} meaningful flags kept open.");

        return self::SUCCESS;
    }
}
