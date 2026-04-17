<?php

namespace App\Listeners;

use App\Services\DebugLogService;
use Illuminate\Mail\Events\MessageSent;

/**
 * Logs every email Laravel sends. Fires on MessageSent (post-dispatch to
 * the transport) rather than MessageSending so we only record actual sends.
 *
 * Template detection: Mailables set an X-Mail-Template header in their
 * build() method (see SendBookingConfirmationMail etc). If not set, we
 * fall back to 'unknown'.
 */
class LogMailEvents
{
    public function __construct(protected DebugLogService $log) {}

    public function handleSent(MessageSent $event): void
    {
        $message = $event->sent->getSymfonySentMessage() ?? null;
        $email   = $event->message;

        $to = collect($email->getTo() ?? [])->first();
        $recipient = $to?->getAddress() ?? '(unknown)';

        $template = $this->detectTemplate($email->getHeaders());
        $subject  = $email->getSubject() ?: '(no subject)';

        $this->log->mail($recipient, $template, [
            'subject' => $subject,
            'cc'      => collect($email->getCc() ?? [])->map->getAddress()->all(),
            'bcc'     => collect($email->getBcc() ?? [])->map->getAddress()->all(),
            'message_id' => $message?->getMessageId(),
        ]);
    }

    protected function detectTemplate($headers): string
    {
        if (! $headers) return 'unknown';

        // Preferred: explicit header set by Mailables
        //   ->withSymfonyMessage(fn($m) => $m->getHeaders()->addTextHeader('X-Mail-Template', 'booking.confirmation'))
        if ($headers->has('X-Mail-Template')) {
            return (string) $headers->get('X-Mail-Template')->getBodyAsString();
        }

        // Fallback: some Mailables might set X-Mailer-Template
        if ($headers->has('X-Mailer-Template')) {
            return (string) $headers->get('X-Mailer-Template')->getBodyAsString();
        }

        return 'unknown';
    }

    public function subscribe(): array
    {
        return [
            MessageSent::class => 'handleSent',
        ];
    }
}
