<?php
// MARKER-SALES-INVITE

namespace App\Mail;

use App\Models\SalesProspect;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesTrialInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SalesProspect $prospect,
        public string $signupUrl,
        public ?string $ownerName,
        public ?string $message,
        public ?string $sentBy,
        public ?string $replyTo,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address(\App\Models\PlatformSettings::fromAddress() ?: 'hello@intake.works', \App\Models\PlatformSettings::fromName() ?: 'Intake');
        $env = new Envelope(from: $from, subject: 'Your Intake trial for ' . $this->prospect->shop);
        return $this->replyTo ? $env->replyTo([new Address($this->replyTo, $this->sentBy ?: 'Intake')]) : $env;
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales-trial-invite', with: [
            'prospect'  => $this->prospect,
            'signupUrl' => $this->signupUrl,
            'ownerName' => $this->ownerName,
            'note'      => $this->message, // 'message' is reserved in mail views
            'sentBy'    => $this->sentBy,
            'plan'      => ucfirst((string) $this->prospect->invite_plan),
        ]);
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(text: ['X-Mail-Template' => 'sales-trial-invite']);
    }
}
