<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUser $user,
        public readonly string $setupUrl,
        public readonly string $inviterName = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: "You're invited to " . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invite',
            with: [
                'tenant'   => $this->tenant,
                'user'     => $this->user,
                'setupUrl' => $this->setupUrl,
                'inviter'  => $this->inviterName,
                'vars'     => [
                    'name'        => $this->user->name,
                    'setup_url'   => $this->setupUrl,
                    'shop_name'   => $this->tenant->name,
                    'accent'      => $this->tenant->accent_color ?? '#BEF264',
                    'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
                ],
            ]
        );
    }
}
