<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUser $user,
        public readonly string $resetUrl
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
        return new Content(
            view: 'emails.password-reset',
            with: [
                'tenant'   => $this->tenant,
                'user'     => $this->user,
                'resetUrl' => $this->resetUrl,
                'vars'     => [
                    'name'         => $this->user->name,
                    'reset_url'    => $this->resetUrl,
                    'shop_name'    => $this->tenant->name,
                    'accent'       => $this->tenant->accent_color ?? '#BEF264',
                    'accent_text'  => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
                ],
            ]
        );
    }
}
