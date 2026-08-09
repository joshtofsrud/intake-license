<?php

namespace App\Mail;

// MARKER-CUST-ACCOUNT — staff-initiated "set up your account" email. Carries
// the same token the customer reset flow validates, so one code path owns
// token checking and expiry.

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerAccountInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantCustomer $customer,
        public readonly string $token,
        public readonly Tenant $tenant
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: 'Set up your account — ' . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        $url = route('tenant.customer.reset', [
            'token' => $this->token,
            'email' => $this->customer->email,
        ]);

        return new Content(
            view: 'emails.customer-account-invite',
            with: [
                'tenant'   => $this->tenant,
                'customer' => $this->customer,
                'setupUrl' => $url,
                'accent'      => $this->tenant->accent_color ?? '#BEF264',
                'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
            ]
        );
    }
}
