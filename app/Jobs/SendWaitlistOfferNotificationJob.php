<?php

namespace App\Jobs;

use App\Mail\WaitlistOfferMail;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Models\Tenant\TenantWaitlistSettings;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWaitlistOfferNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries    = 3;
    public int $backoff  = 60;

    public function __construct(public readonly string $offerId) {}

    public function handle(): void
    {
        $offer = TenantWaitlistOffer::with(['entry.customer', 'entry.serviceItem', 'tenant'])
            ->find($this->offerId);
        if (!$offer) return;

        $tenant   = $offer->tenant;
        $entry    = $offer->entry;
        $customer = $entry?->customer;
        if (!$tenant || !$entry || !$customer) return;

        $settings = TenantWaitlistSettings::forTenant($tenant);

        $emailOk = false;
        $smsOk   = false;
        $emailError = null;
        $smsError   = null;

        // MARKER-WAITLIST-SUPPRESSION — a waitlist entry is a request to be
        // told, so a marketing unsubscribe does not cancel it. A bounce or a
        // complaint does: one is a mailbox that is not there, the other is
        // someone who reported this shop for spam.
        if ($settings->notify_email && $customer->email
            && \App\Models\Tenant\TenantEmailSuppression::isUndeliverable($tenant->id, $customer->email)) {
            Log::info('Waitlist email skipped — address bounced or complained', [
                'offer_id' => $offer->id,
                'tenant'   => $tenant->id,
            ]);
            $emailError = 'suppressed: undeliverable';
        } elseif ($settings->notify_email && $customer->email) {
            // MARKER-LEDGER-STRAGGLERS
            $ledger = \App\Services\EmailLedger::begin($tenant->id, 'reminder', $customer->email, 'waitlist_offer');
            try {
                Mail::to($customer->email)->send(new WaitlistOfferMail($tenant, $offer));
                \App\Services\EmailLedger::markSent($ledger);
                $emailOk = true;
            } catch (\Throwable $e) {
                \App\Services\EmailLedger::void($ledger);
                $emailError = $e->getMessage();
                \App\Support\JobFailureReporter::report(self::class, 'Waitlist offer email did not send', $e,   // MARKER-JOB-ISSUES-2
                    ['offer_id' => $offer->id], $offer->tenant_id ?? null);
                Log::error('Waitlist email failed', [
                    'offer_id' => $offer->id,
                    'error'    => $emailError,
                ]);
            }
        }

        if ($settings->notify_sms && $customer->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $this->buildSmsBody($offer));
                $smsOk = true;
            } catch (\Throwable $e) {
                $smsError = $e->getMessage();
                \App\Support\JobFailureReporter::report(self::class, 'Waitlist offer text did not send', $e,   // MARKER-JOB-ISSUES-2
                    ['offer_id' => $offer->id], $offer->tenant_id ?? null);
                Log::error('Waitlist SMS failed', [
                    'offer_id' => $offer->id,
                    'error'    => $smsError,
                ]);
            }
        }

        $offer->update([
            'notified_at' => now(),
            'email_sent'  => $emailOk,
            'sms_sent'    => $smsOk,
            'email_error' => $emailError,
            'sms_error'   => $smsError,
        ]);
    }

    private function buildSmsBody(TenantWaitlistOffer $offer): string
    {
        $tenant = $offer->tenant;
        $svc    = $offer->entry?->serviceItem?->name ?? 'your service';
        $slot   = $offer->slot_datetime->format('D M j, g:i A');
        $scheme = config('app.url_scheme', 'https');
        $host   = $tenant->subdomain . '.' . config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $link   = $scheme . '://' . $host . '/waitlist/offer/' . $offer->offer_token;

        return sprintf(
            "%s: a spot opened up for your %s on %s. Offered to other waitlist customers too — first to confirm gets it. Confirm: %s",
            $tenant->name,
            $svc,
            $slot,
            $link
        );
    }
}
