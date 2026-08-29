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
            ->with('item.distributorCatalog')
            ->where('status', 'open')
            ->where('reason', TenantPricingAttentionFlag::REASON_TITLE_CHANGED);
        if ($this->option('tenant')) {
            $q->where('tenant_id', $this->option('tenant'));
        }

        $trivial = 0;
        $kept = 0;
        $gone = 0;
        $unjudgeable = 0;
        $q->lazyById(500)->each(function (TenantPricingAttentionFlag $flag) use ($threshold, $apply, &$trivial, &$kept, &$gone, &$unjudgeable) {
            // Prefer the LIVE pair: what the item last accepted vs what the
            // catalog says now. Legacy flags carry no old/new in detail and
            // must not default to "fully changed".
            $item = $flag->item;
            $catTitle = $item?->distributorCatalog?->display_name;
            $d = $flag->detail ?? [];

            if ($item && filled($catTitle)) {
                if ((string) $item->catalog_title_seen === (string) $catTitle) {
                    // Drift no longer exists at all — the flag is stale.
                    $gone++;
                    if ($apply) {
                        $flag->update(['status' => 'resolved', 'resolved_at' => now()]);
                    }

                    return;
                }
                $ratio = TenantDistributorSyncService::titleChangeRatio($item->catalog_title_seen, $catTitle);
            } elseif (filled($d['old'] ?? null) && filled($d['new'] ?? null)) {
                $ratio = TenantDistributorSyncService::titleChangeRatio($d['old'], $d['new']);
            } else {
                // No live pair and no recorded pair: nothing to measure, keep.
                $unjudgeable++;

                return;
            }

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
        $this->info("Threshold {$threshold}: {$verb} {$trivial} trivial + {$gone} already-caught-up title flags; {$kept} meaningful kept open" . ($unjudgeable ? ", {$unjudgeable} unjudgeable kept" : '') . '.');

        return self::SUCCESS;
    }
}
