<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantWaitlistOffer $offer
    ) {}

    public function envelope(): Envelope
    {
        $svc = EmailService::forTenant($this->tenant);
        $subject = $svc->interpolate(
            $this->tenant->emailTemplate('waitlist_offer')['subject']
                ?? 'A spot opened up for your {{service_name}} at {{shop_name}}',
            $this->vars()
        );

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            replyTo: $this->tenant->email_reply_to
                ? [new \Illuminate\Mail\Mailables\Address($this->tenant->email_reply_to)]
                : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-offer',
            with: [
                'tenant' => $this->tenant,
                'offer'  => $this->offer,
                'vars'   => $this->vars(),
            ]
        );
    }

    private function vars(): array
    {
        $offer   = $this->offer;
        $entry   = $offer->entry;
        $svc     = $entry?->serviceItem;
        $cust    = $entry?->customer;
        $slot    = $offer->slot_datetime;

        return [
            'first_name'    => $cust?->first_name ?? '',
            'last_name'     => $cust?->last_name ?? '',
            'service_name'  => $svc?->name ?? '',
            'slot_datetime' => $slot?->format('l, F j, Y \a\t g:i A') ?? '',
            'slot_date'     => $slot?->format('l, F j, Y') ?? '',
            'slot_time'     => $slot?->format('g:i A') ?? '',
            'shop_name'     => $this->tenant->name,
            'accept_url'    => $this->acceptUrl(),
            'accent'        => $this->tenant->accent_color ?? '#BEF264',
            'accent_text'   => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
            'offer_copy'    => $this->offerCopy(),
        ];
    }

    private function acceptUrl(): string
    {
        $scheme = config('app.url_scheme', 'https');
        $host   = $this->tenant->subdomain . '.' . config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
        return $scheme . '://' . $host . '/waitlist/offer/' . $this->offer->offer_token;
    }

    private function offerCopy(): string
    {
        $settings = \App\Models\Tenant\TenantWaitlistSettings::forTenant($this->tenant);
        if ($settings->offer_copy_override) {
            return $settings->offer_copy_override;
        }
        return 'This opening has been offered to other customers on our waitlist. The first person to confirm gets the spot. If you can\'t make this time, no action needed — you\'ll stay on the waitlist.';
    }
}
