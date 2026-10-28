<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * MARKER-TEMP-PASSWORD: sent when a manager resets a team member's password,
 * alongside the dialog that shows it once. Not queued: the temporary password
 * would otherwise sit in the jobs table.
 */
class TeamPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUser $user,
        public readonly string $tempPassword,
        public readonly string $loginUrl,
        public readonly string $resetBy = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->tenant->emailFromAddress(), $this->tenant->emailFromName()),
            subject: 'Your ' . $this->tenant->name . ' password was reset',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-password-reset', with: [
            'tenant' => $this->tenant, 'user' => $this->user, 'tempPassword' => $this->tempPassword,
            'loginUrl' => $this->loginUrl, 'resetBy' => $this->resetBy,
        ]);
    }
}
