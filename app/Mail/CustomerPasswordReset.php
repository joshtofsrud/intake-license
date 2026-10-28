<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantCustomer $customer,
        public readonly string $token,
        public readonly Tenant $tenant,
        // MARKER-EMAIL-FIRST-SIGNUP: sent from Create account to someone who already has one
        public readonly bool $alreadyHasAccount = false
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: ($this->alreadyHasAccount ? 'You already have an account — ' : 'Reset your password — ') . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        // the shop's own address (see Tenant::urlTo).
        $resetUrl = $this->tenant->urlTo('account/reset', [
            'token' => $this->token,
            'email' => $this->customer->email,
        ]);

        return new Content(
            view: 'emails.customer-password-reset',
            with: [
                'tenant'      => $this->tenant,
                'customer'    => $this->customer,
                'resetUrl'    => $resetUrl,
                'alreadyHasAccount' => $this->alreadyHasAccount,
                'accent'      => $this->tenant->accent_color ?? '#BEF264',
                'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
            ]
        );
    }
}
