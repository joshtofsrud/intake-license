<?php

namespace App\Console\Commands;

use App\Jobs\DeliverGiftCardJob;
use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use Illuminate\Console\Command;

/**
 * MARKER-GIFTCARDS-PUBLIC — dispatch delivery for e-gifts that are due:
 * scheduled deliver_on dates that have arrived (tenant-local), plus any
 * immediate delivery whose issue-time job failed. DeliverGiftCardJob is
 * idempotent via delivered_at, so re-dispatching is harmless.
 */
class GiftCardsDeliver extends Command
{
    protected $signature = 'gift-cards:deliver';
    protected $description = 'Send e-gift cards whose delivery date has arrived.';

    public function handle(): int
    {
        $sent = 0;

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use (&$sent) {
            foreach ($tenants as $tenant) {
                $today = $tenant->localToday()->toDateString();

                $due = TenantGiftCard::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('type', 'egift')
                    ->where('status', 'active')
                    ->whereNull('delivered_at')
                    ->whereNotNull('recipient_email')
                    ->where(function ($w) use ($today) {
                        $w->whereNull('deliver_on')->orWhere('deliver_on', '<=', $today);
                    })
                    ->limit(200)
                    ->pluck('id');

                foreach ($due as $id) {
                    DeliverGiftCardJob::dispatch($id);
                    $sent++;
                }
            }
        });

        $this->info("gift-cards:deliver dispatched {$sent} deliveries");

        return self::SUCCESS;
    }
}
