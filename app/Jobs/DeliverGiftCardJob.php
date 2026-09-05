<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Services\EmailService;
use App\Services\Tenant\GiftCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GIFTCARDS — email an e-gift card to its recipient and stamp
 * delivered_at. Dispatched at issue time and by gift-cards:deliver.
 * Idempotent via the delivered_at check.
 *
 * MARKER-GC-EMAILS — now renders through EmailService::send() against the
 * 'gift_card_delivery' template, so edits made in the Communication Center
 * actually reach customers, and the shop's on/off toggle is honored.
 * delivered_at is only stamped when the send is attempted, so a card
 * suppressed by the toggle stays deliverable if the shop turns it back on.
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

        if (! $tenant->notificationEnabled('gift_card_delivery_email')) {
            return;
        }

        try {
            $cfg = GiftCardService::config($tenant);
            $balanceUrl = rtrim((string) $tenant->publicUrl(), '/') . '/gift-cards/balance';

            EmailService::forTenant($tenant)->send('gift_card_delivery', $card->recipient_email, [
                'recipient_name' => $card->recipient_name ?: 'Hello',
                'shop_name'      => $tenant->name,
                'card_amount'    => '$' . number_format($card->balance_cents / 100, 2),
                'card_code'      => $card->code,
                'gift_message'   => (string) ($card->gift_message ?? ''),
                'gift_policy'    => $cfg['policy_line'],
                'balance_url'    => $balanceUrl,
                'first_name'     => $card->recipient_name ?: 'there',
            ]);

            $card->update(['delivered_at' => now()]);
        } catch (\Throwable $e) {
            \App\Support\JobFailureReporter::report(self::class, 'Gift card was not delivered to the recipient', $e,   // MARKER-JOB-ISSUES-2
                ['gift_card_id' => $card->id], $card->tenant_id);
            Log::warning('gift_card.delivery_failed', [
                'gift_card_id' => $card->id,
                'tenant_id'    => $card->tenant_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
