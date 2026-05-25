<?php
// MARKER-PATCH-143

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

/**
 * TestSendMail — generic test email used by the "Send test" button on
 * /admin/settings → Email sender details. Verifies the from-address,
 * reply-to, and subject formatting are all wired correctly without
 * pulling in any template-specific data.
 *
 * Sends from the tenant's emailFromAddress / emailFromName (NOT from
 * Intake's MAIL_FROM_ADDRESS) — the whole point is to verify the
 * tenant's outbound configuration.
 */
class TestSendMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        $env = new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: 'Test email — ' . $this->shopName,
        );
        if ($this->replyTo) {
            $env = new Envelope(
                from: new Address($this->fromEmail, $this->fromName),
                replyTo: [new Address($this->replyTo)],
                subject: 'Test email — ' . $this->shopName,
            );
        }
        return $env;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test-send',
            with: [
                'fromEmail' => $this->fromEmail,
                'fromName'  => $this->fromName,
                'replyTo'   => $this->replyTo,
                'shopName'  => $this->shopName,
            ],
        );
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: ['X-Mail-Template' => 'test', 'X-Mail-Is-Test' => '1'],
        );
    }
}
