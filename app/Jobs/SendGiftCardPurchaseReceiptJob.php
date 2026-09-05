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
 * MARKER-GC-EMAILS — confirmation to whoever BOUGHT a gift card online.
 * Deliberately never contains the code: for an e-gift the code belongs to
 * the recipient, and for a pickup card it is read out in store.
 */
class SendGiftCardPurchaseReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $giftCardId)
    {
    }

    public function handle(): void
    {
        $card = TenantGiftCard::find($this->giftCardId);
        if (! $card || $card->status === 'pending' || blank($card->purchaser_email)) {
            return;
        }

        $tenant = Tenant::find($card->tenant_id);
        if (! $tenant || ! $tenant->notificationEnabled('gift_card_purchase_receipt_email')) {
            return;
        }

        $isEgift = $card->type === 'egift';

        if ($isEgift) {
            $delivery = $card->deliver_on
                ? 'Emailed to ' . $card->recipient_email . ' on ' . $card->deliver_on->format('M j, Y')
                : 'Emailed to ' . $card->recipient_email;
            $next = 'The card code is in the recipient\'s email — you don\'t need to pass anything along.';
        } else {
            $delivery = 'Ready to pick up in store';
            $next = 'Bring this email with you and we\'ll hand over the card.';
        }

        try {
            EmailService::forTenant($tenant)->send('gift_card_purchase_receipt', $card->purchaser_email, [
                'first_name'     => $card->purchaser_name ?: 'there',
                'shop_name'      => $tenant->name,
                'card_amount'    => '$' . number_format($card->original_cents / 100, 2),
                'card_type'      => $isEgift ? 'E-gift card' : 'Physical card',
                'card_delivery'  => $delivery,
                'card_next_step' => $next,
            ]);
        } catch (\Throwable $e) {
            Log::warning('gift_card.purchase_receipt_failed', [
                'gift_card_id' => $card->id,
                'error'        => $e->getMessage(),
            ]);
            \App\Support\JobFailureReporter::report(self::class, 'Gift card purchase receipt did not send', $e,   // MARKER-JOB-ISSUES-2
                ['gift_card_id' => $card->id], $card->tenant_id);
        }
    }
}
