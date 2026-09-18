<?php

namespace App\Support;

use App\Models\PlatformEmailTemplate;
use Illuminate\Support\Facades\View;

/**
 * MARKER-PLATFORM-TEMPLATES — what Intake sends about itself.
 *
 * The registry is the single list. A Mailable asks it for a subject and a
 * body; if nobody has customised that key it hands back nulls and the Mailable
 * renders its shipped Blade exactly as before.
 */
class PlatformEmailTemplates
{
    /**
     * key => [group, label, description, fires, tokens, default_subject]
     *
     * `fires` is the plain-language "when does this go out", shown on the list
     * so the page answers the question before it is asked.
     */
    public const REGISTRY = [
        'welcome' => [
            'group'       => 'Lifecycle',
            'label'       => 'Welcome',
            'description' => 'First email a new shop gets',
            'fires'       => 'A tenant signs up, or you gift one',
            'tokens'      => ['shop_name', 'first_name', 'login_url'],
            'subject'     => 'Welcome to Intake — {{shop_name}}',
        ],
        'password_reset' => [
            'group'       => 'Staff & access',
            'label'       => 'Staff password reset',
            'description' => 'Reset link for a shop\'s staff member',
            'fires'       => 'A staff member requests a reset',
            'tokens'      => ['shop_name', 'first_name', 'reset_url'],
            'subject'     => 'Reset your password — {{shop_name}}',
        ],
        'team_invite' => [
            'group'       => 'Staff & access',
            'label'       => 'Team invite',
            'description' => 'Invitation to join a shop\'s team',
            'fires'       => 'An owner invites someone',
            'tokens'      => ['shop_name', 'first_name', 'invite_url'],
            'subject'     => 'You\'re invited to {{shop_name}}',
        ],
    ];

    /**
     * MARKER-PLATFORM-MSG-COMPLETE — every email the platform sends, including
     * the ones this page cannot edit.
     *
     * A list that shows three rows while a dozen senders exist is worse than no
     * list: it answers "is that everything?" with a confident no-but-looks-like-
     * yes. So each entry declares its `edit` mode and, when it is not editable
     * here, says where it lives or why it is fixed.
     */
    public const OTHER_SENDERS = [
        [
            'group' => 'Billing',
            'label' => 'Billing notices',
            'fires' => 'Invoices, payment failures, trial and dunning notices',
            'edit'  => 'elsewhere',
            'where' => '/admin/billing-notices',
            'note'  => 'Already editable, with their own tokens and per-event rows.',
        ],
        [
            'group' => 'Raise',
            'label' => 'Investor messages',
            'fires' => 'Invites, updates and document notices to investors',
            'edit'  => 'elsewhere',
            'where' => '/admin/raise-setup',
            'note'  => 'Edited on Raise setup, defaults merged with your overrides.',
        ],
        [
            'group' => 'Scheduling',
            'label' => 'Call booking confirmation',
            'fires' => 'Someone books a call with you, and on reschedule or cancel',
            'edit'  => 'fixed',
            'note'  => 'Renders a details table and attaches a calendar invite — text editing would break both.',
        ],
        [
            'group' => 'Tenant features',
            'label' => 'Waitlist offer',
            'fires' => 'A shop\'s waitlist offer goes to their customer',
            'edit'  => 'fixed',
            'note'  => 'Sent on the shop\'s behalf to their customer, so it follows the shop\'s branding, not yours.',
        ],
        [
            'group' => 'Alerts to you',
            'label' => '500 error alerts',
            'fires' => 'A server error, at most one per site every 15 minutes',
            'edit'  => 'fixed',
            'note'  => 'Plain text to you alone. Nothing to design.',
        ],
        [
            'group' => 'Alerts to you',
            'label' => 'Job failure reports',
            'fires' => 'A background job throws',
            'edit'  => 'fixed',
            'note'  => 'Carries the reference id that matches the log line and the inbox alert.',
        ],
        [
            'group' => 'Alerts to you',
            'label' => 'New inbox message',
            'fires' => 'Someone writes in through the contact form',
            'edit'  => 'fixed',
            'note'  => 'A nudge to go and read it, not a message in its own right.',
        ],
    ];

    public static function override(string $key): ?PlatformEmailTemplate
    {
        try {
            $row = PlatformEmailTemplate::find($key);
        } catch (\Throwable $e) {
            return null; // table not migrated yet — shipped Blade still renders
        }

        return ($row && $row->enabled && trim((string) $row->body) !== '') ? $row : null;
    }

    /** Custom subject for this key, or null to keep the Mailable's own. */
    public static function subject(string $key, array $vars = []): ?string
    {
        $row = self::override($key);
        $sub = $row ? trim((string) $row->subject) : '';

        return $sub !== '' ? self::merge($sub, $vars) : null;
    }

    /** Rendered HTML for this key, or null to keep the shipped Blade. */
    public static function html(string $key, array $vars = []): ?string
    {
        $row = self::override($key);
        if (! $row) {
            return null;
        }

        return View::make('emails.platform.custom', [
            'bodyHtml' => nl2br(e(self::merge((string) $row->body, $vars))),
            'postal'   => \App\Services\Platform\PlatformMailer::postalAddress(),
        ])->render();
    }

    /** Sample values so preview and test sends read like a real email. */
    public static function sampleVars(string $key): array
    {
        return [
            'shop_name'  => 'Cascade Cyclery',
            'first_name' => 'Sam',
            'login_url'  => 'https://cascade.intake.works/login',
            'reset_url'  => 'https://cascade.intake.works/password/reset/example',
            'invite_url' => 'https://cascade.intake.works/invite/example',
        ];
    }

    public static function merge(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{{' . $k . '}}', (string) $v, $text);
        }

        // Anything left unresolved would ship as literal braces. Strip rather
        // than print {{first_name}} to a real person.
        return preg_replace('/\{\{\s*[a-z_]+\s*\}\}/i', '', $text);
    }
}
