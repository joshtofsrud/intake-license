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
            // MARKER-PLATFORM-TEMPLATES — a customised subject wins; with no
            // override this is exactly the string that shipped.
            subject: \App\Support\PlatformEmailTemplates::subject('team_invite', $this->templateVars())
                ?: "You're invited to " . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            // MARKER-PLATFORM-TEMPLATES — htmlString only when customised,
            // so an untouched template renders its shipped Blade unchanged.
            htmlString: \App\Support\PlatformEmailTemplates::html('team_invite', $this->templateVars()),
            view: \App\Support\PlatformEmailTemplates::html('team_invite', $this->templateVars()) ? null : 'emails.team-invite',
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

    /** MARKER-PLATFORM-TEMPLATES — values a customised template can use. */
    protected function templateVars(): array
    {
        $vars = [];

        if (isset($this->tenant)) {
            $vars['shop_name'] = (string) $this->tenant->name;
            if (! empty($this->tenant->subdomain)) {
                $vars['login_url'] = 'https://' . $this->tenant->subdomain . '.intake.works/login';
            }
        }

        foreach (['user', 'invitee', 'staff'] as $who) {
            if (isset($this->{$who}) && ! empty($this->{$who}->name)) {
                $vars['first_name'] = explode(' ', (string) $this->{$who}->name)[0];
                break;
            }
        }

        foreach (['resetUrl' => 'reset_url', 'inviteUrl' => 'invite_url', 'url' => 'invite_url'] as $prop => $token) {
            if (isset($this->{$prop}) && ! isset($vars[$token])) {
                $vars[$token] = (string) $this->{$prop};
            }
        }

        return $vars;
    }
}
