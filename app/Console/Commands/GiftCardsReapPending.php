<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Services\Tenant\GiftCardService;
use Illuminate\Console\Command;

/**
 * MARKER-GC-FUNCTIONS — clear abandoned online gift card purchases.
 *
 * A card row is created before payment so the Stripe intent has something to
 * point at; a checkout the customer walks away from leaves it pending
 * forever, holding a code. This deletes those rows per the shop's retention
 * setting.
 *
 * Deliberately narrow: pending only, past the retention, with no ledger row
 * and no delivered_at. Anything that ever took money or moved a balance is
 * out of scope no matter how old, because a wrongly reaped card is a
 * customer holding a code for money we can no longer see.
 */
class GiftCardsReapPending extends Command
{
    protected $signature = 'gift-cards:reap-pending {--dry-run : Report what would be deleted without deleting}';
    protected $description = 'Delete abandoned (never paid) online gift card purchases per tenant retention.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use (&$total, $dry) {
            foreach ($tenants as $tenant) {
                $days = GiftCardService::config($tenant)['pending_days'];
                if ($days < 1) {
                    continue; // "keep forever"
                }

                $cutoff = now()->subDays($days);

                $ids = TenantGiftCard::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'pending')
                    ->whereNull('delivered_at')
                    ->where('created_at', '<', $cutoff)
                    ->whereNotExists(function ($q) {
                        $q->select(\Illuminate\Support\Facades\DB::raw(1))
                          ->from('tenant_gift_card_transactions')
                          ->whereColumn('tenant_gift_card_transactions.gift_card_id', 'tenant_gift_cards.id');
                    })
                    ->limit(500)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    continue;
                }

                $total += $ids->count();

                if (! $dry) {
                    TenantGiftCard::whereIn('id', $ids)->delete();
                }
            }
        });

        $this->info(($dry ? '[dry-run] ' : '') . "gift-cards:reap-pending cleared {$total} abandoned purchases");

        return self::SUCCESS;
    }
}
