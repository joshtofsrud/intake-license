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
            // MARKER-MAIL-FROM — config('key', 'fallback') does NOT fall back when
            // the key exists and is wrong, and with no config/mail.php in this
            // repo it resolved to the framework placeholder. Every welcome email
            // was addressed from example.com, which has no sender signature.
            from: new Address(
                \App\Models\PlatformSettings::fromAddress() ?: \App\Models\PlatformSettings::fromAddress(),
                \App\Models\PlatformSettings::fromName() ?: 'Intake'
            ),
            // MARKER-PLATFORM-TEMPLATES — a customised subject wins; with no
            // override this is exactly the string that shipped.
            subject: \App\Support\PlatformEmailTemplates::subject('welcome', $this->templateVars())
                ?: 'Welcome to Intake — ' . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            // MARKER-PLATFORM-TEMPLATES — htmlString only when customised,
            // so an untouched template renders its shipped Blade unchanged.
            htmlString: \App\Support\PlatformEmailTemplates::html('welcome', $this->templateVars()),
            view: \App\Support\PlatformEmailTemplates::html('welcome', $this->templateVars()) ? null : 'emails.welcome',
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
