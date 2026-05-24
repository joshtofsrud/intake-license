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
        public readonly Tenant $tenant
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: 'Reset your password — ' . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        $resetUrl = route('tenant.customer.reset', [
            'token'     => $this->token,
            'email'     => $this->customer->email,
        ]);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'tenant'   => $this->tenant,
                'user'     => $this->customer,
                'resetUrl' => $resetUrl,
                'vars'     => [
                    'name'        => $this->customer->fullName(),
                    'reset_url'   => $resetUrl,
                    'shop_name'   => $this->tenant->name,
                    'accent'      => $this->tenant->accent_color ?? '#BEF264',
                    'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
                ],
            ]
        );
    }
}
