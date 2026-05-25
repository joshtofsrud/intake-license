<?php
// MARKER-PATCH-143

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

/**
 * WelcomeEmail — sent by Intake-the-platform to a new tenant owner.
 *
 * Sends from MAIL_FROM_ADDRESS (hello@intake.works), NOT from the
 * tenant's emailFromAddress(). This is Intake speaking to the tenant,
 * not the tenant speaking to its customers.
 */
class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUser $user,
        public readonly ?string $tempPassword = null,
        public readonly string $source = 'signup',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'hello@intake.works'),
                config('mail.from.name', 'Intake')
            ),
            subject: 'Welcome to Intake — ' . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: [
                'tenant'       => $this->tenant,
                'user'         => $this->user,
                'tempPassword' => $this->tempPassword,
                'loginUrl'     => 'https://' . $this->tenant->subdomain . '.intake.works/login',
                'source'       => $this->source,
            ],
        );
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: ['X-Mail-Template' => 'welcome'],
        );
    }
}
