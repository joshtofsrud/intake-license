<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GIFTCARDS — email an e-gift card to its recipient and stamp
 * delivered_at. Dispatched at issue time (immediate delivery) and by the
 * gift-cards:deliver scheduler (scheduled deliver_on dates, patch 3).
 * Idempotent via the delivered_at check.
 */
class DeliverGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $giftCardId)
    {
    }

    public function handle(): void
    {
        $card = TenantGiftCard::find($this->giftCardId);
        if (! $card || $card->delivered_at !== null || $card->status !== 'active' || blank($card->recipient_email)) {
            return;
        }

        $tenant = Tenant::find($card->tenant_id);
        if (! $tenant) {
            return;
        }

        try {
            $html = view('emails.gift-card', [
                'tenant' => $tenant,
                'card'   => $card,
            ])->render();

            $ok = (new EmailService($tenant))->sendRendered(
                'gift_card',
                $card->recipient_email,
                'You\'ve received a ' . $tenant->name . ' gift card',
                $html
            );

            if ($ok) {
                $card->update(['delivered_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('gift_card.delivery_failed', [
                'gift_card_id' => $card->id,
                'tenant_id'    => $card->tenant_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
