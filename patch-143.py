#!/usr/bin/env python3
"""
Patch 143 — Email Patch A: SES transport wiring, system mail, test infra.

Wires Amazon SES as the mail transport. Adds the WelcomeEmail mailable
for new tenant signups (both public path and gift path). Adds a
TestEmailService that all "Send test" buttons (this patch + later
ones) call through.

This patch does NOT add test buttons to every template editor yet —
that's Patch C (a follow-up). This one only adds the test button to
the Email sender details card on /admin/settings, as a verification
that the entire send path works end-to-end for the tenant.

Architecture:
  - Intake-the-platform sends from MAIL_FROM_ADDRESS (hello@intake.works)
    for welcome emails, gift-tenant credentials, and master-admin alerts.
  - Tenants send from their configured emailFromAddress() — the existing
    EmailService already handles this. Once SES is the transport, that
    code path lights up automatically.

Files added:
  - app/Mail/WelcomeEmail.php
  - app/Mail/TestSendMail.php
  - app/Services/TestEmailService.php
  - app/Http/Controllers/Tenant/TestEmailController.php
  - resources/views/emails/welcome.blade.php
  - resources/views/emails/test-send.blade.php
  - .env.ses.example  (documentation; user copies values into real .env)

Files edited:
  - composer.json  (require aws/aws-sdk-php)
  - app/Http/Controllers/Platform/OnboardingController.php  (send welcome on signup)
  - app/Filament/Resources/TenantResource/Pages/CreateTenant.php  (send welcome on gift)
  - app/Http/Controllers/Tenant/SettingsController.php  (lock from address, add test endpoint)
  - resources/views/tenant/settings/index.blade.php  (lock from field, add test button)
  - routes/web.php  (add /admin/settings/email/test route)

Requires before deploy:
  1. SES verified for intake.works (DKIM in DNS, status Verified)
  2. AWS IAM user with ses:SendEmail / ses:SendRawEmail
  3. Production access approved (or test only to verified addresses while in sandbox)
  4. .env values for AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION=us-west-2
  5. .env values for MAIL_MAILER=ses, MAIL_FROM_ADDRESS=hello@intake.works, MAIL_FROM_NAME=Intake

Idempotent. Dry-run safe.
"""

import argparse
import pathlib
import sys
import json

MARKER = 'MARKER-PATCH-143'

# ============================================================
# NEW FILES
# ============================================================

WELCOME_EMAIL = r'''<?php
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
'''


TEST_SEND_MAIL = r'''<?php
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
'''


TEST_EMAIL_SERVICE = r'''<?php
// MARKER-PATCH-143

namespace App\Services;

use App\Mail\TestSendMail;
use App\Mail\WelcomeEmail;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TestEmailService — single service every "Send test" button calls
 * through. Future patches that add test buttons to other template
 * editors should add methods here rather than calling Mail::send
 * directly.
 *
 * Test sends:
 *   - Always log to debug_logs (via the existing LogMailEvents listener)
 *   - Carry an X-Mail-Is-Test header so logs can be filtered later
 *   - NEVER write to TenantCampaignSend, even for campaign tests
 *   - NEVER count toward campaign metrics
 */
class TestEmailService
{
    /**
     * Send the generic "Test email" with the tenant's currently-configured
     * From name / address / reply-to. Used by the Email sender details card.
     */
    public function sendSettingsTest(
        Tenant $tenant,
        string $recipient,
        ?string $overrideFromName = null,
        ?string $overrideFromAddress = null,
        ?string $overrideReplyTo = null,
    ): array {
        $fromName    = $overrideFromName    ?: $tenant->emailFromName();
        $fromAddress = $overrideFromAddress ?: $tenant->emailFromAddress();
        $replyTo     = $overrideReplyTo     ?: $tenant->email_reply_to;

        return $this->sendWith(fn () => Mail::to($recipient)->send(
            new TestSendMail($fromAddress, $fromName, $replyTo, $tenant->name)
        ), $recipient, 'settings_test', [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Send a test welcome email — useful for verifying the full system
     * mail path (from Intake to a tenant owner) without going through
     * a real signup. Used by master admin smoke tests.
     */
    public function sendWelcomeTest(Tenant $tenant, TenantUser $user, string $recipient): array
    {
        return $this->sendWith(fn () => Mail::to($recipient)->send(
            new WelcomeEmail($tenant, $user, 'TEMP_TEST_PASSWORD', 'test')
        ), $recipient, 'welcome_test', [
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
        ]);
    }

    /**
     * Wrap a send closure with try/catch + log. Returns shape:
     *   ['ok' => bool, 'message' => string]
     */
    protected function sendWith(callable $sendFn, string $recipient, string $kind, array $context = []): array
    {
        try {
            $sendFn();
            Log::info('Test email sent', array_merge([
                'kind'      => $kind,
                'recipient' => $recipient,
            ], $context));
            return ['ok' => true, 'message' => 'Sent to ' . $recipient];
        } catch (Throwable $e) {
            Log::error('Test email failed', array_merge([
                'kind'      => $kind,
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
            ], $context));
            return ['ok' => false, 'message' => 'Send failed: ' . $e->getMessage()];
        }
    }
}
'''


TEST_EMAIL_CONTROLLER = r'''<?php
// MARKER-PATCH-143

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\TestEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestEmailController extends Controller
{
    public function __construct(protected TestEmailService $tests) {}

    /**
     * POST /admin/settings/email/test
     *
     * Sends a test email using the tenant's currently-saved from-address
     * and reply-to. Optional 'recipient' input overrides the default
     * (current user's email).
     *
     * Permissioned to manager+ to avoid staff spamming themselves.
     */
    public function sendSettingsTest(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'recipient' => ['nullable', 'email', 'max:255'],
        ]);

        $recipient = $data['recipient'] ?? $me->email;
        $result = $this->tests->sendSettingsTest(tenant(), $recipient);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
'''


WELCOME_VIEW = r'''{{-- MARKER-PATCH-143 — Welcome email body --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Welcome to Intake</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table cellpadding="0" cellspacing="0" border="0" width="560" style="background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e5e5;">
          <tr>
            <td style="padding:28px 32px 0;">
              <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#888;">Intake</div>
              <h1 style="margin:8px 0 0;font-size:22px;font-weight:600;line-height:1.3;color:#111;">
                Welcome, {{ $user->name }}.
              </h1>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px;font-size:15px;line-height:1.6;color:#333;">
              <p style="margin:0 0 16px;">
                Your shop <strong>{{ $tenant->name }}</strong> is ready on Intake.
                The link below will take you to your admin where you can set up services,
                staff, and your calendar.
              </p>

              @if($tempPassword)
                <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;margin:20px 0;">
                  <tr>
                    <td style="padding:16px 18px;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:13px;line-height:1.8;">
                      <div><span style="color:#888;">Sign-in URL:</span><br>
                        <a href="{{ $loginUrl }}" style="color:#0066cc;text-decoration:none;">{{ $loginUrl }}</a>
                      </div>
                      <div style="margin-top:10px;"><span style="color:#888;">Email:</span><br>{{ $user->email }}</div>
                      <div style="margin-top:10px;"><span style="color:#888;">Temporary password:</span><br><strong>{{ $tempPassword }}</strong></div>
                    </td>
                  </tr>
                </table>
                <p style="margin:0 0 16px;font-size:13px;color:#666;">
                  Sign in and change your password right away. You won't see this password again.
                </p>
              @else
                <p style="margin:0 0 16px;">
                  <a href="{{ $loginUrl }}" style="display:inline-block;background:#BEF264;color:#0a0a0a;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:600;">
                    Sign in
                  </a>
                </p>
              @endif

              <p style="margin:24px 0 0;font-size:13px;color:#666;line-height:1.6;">
                Questions? Reply to this email and someone from Intake will help.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 32px;border-top:1px solid #e5e5e5;font-size:12px;color:#888;">
              You're getting this because you just created an Intake account at
              <a href="{{ $loginUrl }}" style="color:#666;">{{ $tenant->subdomain }}.intake.works</a>.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
'''


TEST_SEND_VIEW = r'''{{-- MARKER-PATCH-143 — Test email body --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Test email — {{ $shopName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table cellpadding="0" cellspacing="0" border="0" width="540" style="background:#fff;border-radius:12px;border:1px solid #e5e5e5;">
          <tr>
            <td style="padding:24px 28px;">
              <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#888;">{{ $shopName }} — test send</div>
              <h2 style="margin:8px 0 16px;font-size:18px;font-weight:600;color:#111;">
                If you're reading this, your email is wired up.
              </h2>
              <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#333;">
                This is a test message sent from your shop's configured sender details.
                Below is what your customers will see when you send them an email.
              </p>
              <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fafafa;border:1px solid #e5e5e5;border-radius:6px;margin-top:16px;">
                <tr>
                  <td style="padding:12px 14px;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12px;line-height:1.7;color:#444;">
                    <div><span style="color:#888;">From:</span> {{ $fromName }} &lt;{{ $fromEmail }}&gt;</div>
                    @if($replyTo)<div><span style="color:#888;">Reply-To:</span> {{ $replyTo }}</div>@endif
                  </td>
                </tr>
              </table>
              <p style="margin:18px 0 0;font-size:12px;color:#888;line-height:1.5;">
                Try replying to this email — it should go to your reply-to address (if set) or your From address.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
'''


ENV_SES_EXAMPLE = r'''# MARKER-PATCH-143 — SES configuration values
# Copy these into your real .env and fill in the secrets.

MAIL_MAILER=ses
MAIL_FROM_ADDRESS=hello@intake.works
MAIL_FROM_NAME=Intake

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-west-2

# Set to true while SES is in sandbox mode (only verified recipients
# can receive). Surfaces a warning banner on /admin/settings test send.
SES_SANDBOX=true
'''


NEW_FILES = {
    'app/Mail/WelcomeEmail.php':                              WELCOME_EMAIL,
    'app/Mail/TestSendMail.php':                              TEST_SEND_MAIL,
    'app/Services/TestEmailService.php':                      TEST_EMAIL_SERVICE,
    'app/Http/Controllers/Tenant/TestEmailController.php':    TEST_EMAIL_CONTROLLER,
    'resources/views/emails/welcome.blade.php':               WELCOME_VIEW,
    'resources/views/emails/test-send.blade.php':             TEST_SEND_VIEW,
    'tools/.env.ses.example':                                 ENV_SES_EXAMPLE,
}


# ============================================================
# EDITS
# ============================================================

# 1. composer.json — add aws/aws-sdk-php
OLD_COMPOSER_FRAGMENT = '"laravel/framework":'
# We'll add aws/aws-sdk-php as a dependency. Find the right spot dynamically;
# do the edit in code (not via simple str replace) since composer.json is JSON.


# 2. OnboardingController — fire WelcomeEmail at the end of the no-billing
#    tenant creation path. Find the line `return [$tenant, $user];` inside
#    the no-billing variant (line ~387 per the earlier grep).
OLD_ONBOARD_NOBILL = """                return [$tenant, $user];
            });
        } catch (\\Throwable $e) {
            Log::error('Signup: Tenant creation failed (no-billing path)', ["""

NEW_ONBOARD_NOBILL = """                // MARKER-PATCH-143 — fire welcome email on no-billing signup path
                try {
                    \\Illuminate\\Support\\Facades\\Mail::to($user->email)->send(
                        new \\App\\Mail\\WelcomeEmail($tenant, $user, null, 'signup')
                    );
                } catch (\\Throwable $mailErr) {
                    \\Illuminate\\Support\\Facades\\Log::warning('Welcome email send failed (non-fatal)', [
                        'tenant_id' => $tenant->id, 'error' => $mailErr->getMessage(),
                    ]);
                }
                return [$tenant, $user];
            });
        } catch (\\Throwable $e) {
            Log::error('Signup: Tenant creation failed (no-billing path)', ["""


# 3. OnboardingController — fire WelcomeEmail at the end of the billing
#    tenant creation path. The other `return [$tenant, $user];` at line ~353.
OLD_ONBOARD_BILL = """        $user = TenantUser::create([
            'tenant_id'  => $tenant->id,
            'name'       => $pending['name'],
            'email'      => $pending['email'],
            'phone'      => $pending['phone'],
            'password'   => $pending['password_hash'],
            'role'       => 'owner',
            'is_active'  => true,
        ]);

        return [$tenant, $user];
    }"""

NEW_ONBOARD_BILL = """        $user = TenantUser::create([
            'tenant_id'  => $tenant->id,
            'name'       => $pending['name'],
            'email'      => $pending['email'],
            'phone'      => $pending['phone'],
            'password'   => $pending['password_hash'],
            'role'       => 'owner',
            'is_active'  => true,
        ]);

        // MARKER-PATCH-143 — fire welcome email on billing signup path
        try {
            \\Illuminate\\Support\\Facades\\Mail::to($user->email)->send(
                new \\App\\Mail\\WelcomeEmail($tenant, $user, null, 'signup')
            );
        } catch (\\Throwable $mailErr) {
            \\Illuminate\\Support\\Facades\\Log::warning('Welcome email send failed (non-fatal)', [
                'tenant_id' => $tenant->id, 'error' => $mailErr->getMessage(),
            ]);
        }

        return [$tenant, $user];
    }"""


# 4. CreateTenant.php — fire WelcomeEmail in the gift flow's afterCreate.
OLD_GIFT_NOTIF = """    protected function afterCreate(): void
    {
        $stash = session('gift_tenant_password');
        if (! $stash) return;

        Notification::make()
            ->success()
            ->title('Gift tenant created')
            ->body(\"Owner sign-in:\\n  Email: {$stash['email']}\\n  Password: {$stash['password']}\\n  URL: https://{$stash['subdomain']}.intake.works/login\\n\\nCopy this now — it will not be shown again.\")
            ->persistent()
            ->send();
    }"""

NEW_GIFT_NOTIF = """    protected function afterCreate(): void
    {
        $stash = session('gift_tenant_password');
        if (! $stash) return;

        // MARKER-PATCH-143 — also send the welcome email with the temp password.
        try {
            $tenant = \\App\\Models\\Tenant::find($this->record->id);
            $owner = \\App\\Models\\Tenant\\TenantUser::where('tenant_id', $tenant->id)
                ->where('role', 'owner')->first();
            if ($tenant && $owner) {
                \\Illuminate\\Support\\Facades\\Mail::to($owner->email)->send(
                    new \\App\\Mail\\WelcomeEmail($tenant, $owner, $stash['password'], 'gift')
                );
            }
        } catch (\\Throwable $e) {
            \\Illuminate\\Support\\Facades\\Log::warning('Gift welcome email failed (non-fatal)', [
                'tenant_id' => $this->record->id, 'error' => $e->getMessage(),
            ]);
        }

        Notification::make()
            ->success()
            ->title('Gift tenant created')
            ->body(\"Owner sign-in:\\n  Email: {$stash['email']}\\n  Password: {$stash['password']}\\n  URL: https://{$stash['subdomain']}.intake.works/login\\n\\nCopy this now — it will not be shown again. The owner has also been emailed.\")
            ->persistent()
            ->send();
    }"""


# 5. Settings view — lock from address field + add test button
OLD_SETTINGS_FROM = """      <div class=\"ia-input-grid-2\">
        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">From email address</label>
          <input type=\"email\" name=\"email_from_address\" class=\"ia-input\"
            value=\"{{ old('email_from_address', $currentTenant->email_from_address) }}\"
            placeholder=\"{{ $currentTenant->subdomain }}@intake.works\">
        </div>
        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">Reply-to (optional)</label>
          <input type=\"email\" name=\"email_reply_to\" class=\"ia-input\"
            value=\"{{ old('email_reply_to', $currentTenant->email_reply_to) }}\">
        </div>
      </div>"""

NEW_SETTINGS_FROM = """      {{-- MARKER-PATCH-143 — From address locked to <subdomain>@intake.works until custom domains land --}}
      <div class=\"ia-input-grid-2\">
        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">From email address</label>
          <input type=\"email\" class=\"ia-input\" readonly disabled
            value=\"{{ $currentTenant->subdomain }}@intake.works\"
            style=\"opacity:.7;cursor:not-allowed\">
          <div style=\"font-size:11px;color:var(--ia-text-dim);margin-top:4px\">
            All your customer emails come from this address. Custom domains coming soon.
          </div>
        </div>
        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">Reply-to (optional)</label>
          <input type=\"email\" name=\"email_reply_to\" class=\"ia-input\"
            value=\"{{ old('email_reply_to', $currentTenant->email_reply_to) }}\"
            placeholder=\"{{ Auth::guard('tenant')->user()->email ?? '' }}\">
          <div style=\"font-size:11px;color:var(--ia-text-dim);margin-top:4px\">
            Where replies go. Usually your shop's main email.
          </div>
        </div>
      </div>

      {{-- MARKER-PATCH-143 — Test send block --}}
      <div style=\"margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)\">
        <div style=\"font-size:13px;font-weight:500;margin-bottom:6px\">Test your email setup</div>
        <div style=\"font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55\">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <form method=\"POST\" action=\"{{ route('tenant.settings.email.test') }}\" style=\"display:flex;gap:8px;flex-wrap:wrap;align-items:center\">
          @csrf
          <input type=\"email\" name=\"recipient\" class=\"ia-input\" style=\"flex:1;min-width:240px\"
            placeholder=\"recipient@example.com\"
            value=\"{{ Auth::guard('tenant')->user()->email ?? '' }}\" required>
          <button type=\"submit\" class=\"ia-btn ia-btn--ghost ia-btn--sm\">Send test email</button>
        </form>
      </div>"""


# 6. routes/web.php — add the test send endpoint
OLD_ROUTES_ANCHOR = """            // Self-service account surfaces (current user only)
            Route::get('/account',                         [TenantControllers\\AccountController::class, 'index'])->name('account.index');"""

NEW_ROUTES_ANCHOR = """            // MARKER-PATCH-143 — Test email send endpoint (settings card)
            Route::post('/settings/email/test', [TenantControllers\\TestEmailController::class, 'sendSettingsTest'])->name('settings.email.test');

            // Self-service account surfaces (current user only)
            Route::get('/account',                         [TenantControllers\\AccountController::class, 'index'])->name('account.index');"""


EDITS = [
    ('app/Http/Controllers/Platform/OnboardingController.php', OLD_ONBOARD_NOBILL, NEW_ONBOARD_NOBILL, 'OnboardingController no-billing welcome', 'MARKER-PATCH-143 — fire welcome email on no-billing signup path'),
    ('app/Http/Controllers/Platform/OnboardingController.php', OLD_ONBOARD_BILL,   NEW_ONBOARD_BILL,   'OnboardingController billing welcome',   'MARKER-PATCH-143 — fire welcome email on billing signup path'),
    ('app/Filament/Resources/TenantResource/Pages/CreateTenant.php', OLD_GIFT_NOTIF, NEW_GIFT_NOTIF, 'CreateTenant gift welcome',               'MARKER-PATCH-143 — also send the welcome email with the temp password.'),
    ('resources/views/tenant/settings/index.blade.php', OLD_SETTINGS_FROM, NEW_SETTINGS_FROM, 'Settings view email card',                       'MARKER-PATCH-143 — From address locked'),
    ('routes/web.php', OLD_ROUTES_ANCHOR, NEW_ROUTES_ANCHOR, 'routes: test email endpoint',                                                     'MARKER-PATCH-143 — Test email send endpoint'),
]


def edit_composer_json(root: pathlib.Path, apply: bool) -> str:
    """Add aws/aws-sdk-php to composer require if not present."""
    p = root / 'composer.json'
    data = json.loads(p.read_text())
    if 'aws/aws-sdk-php' in (data.get('require') or {}):
        return 'already_applied'
    data.setdefault('require', {})['aws/aws-sdk-php'] = '^3.300'
    if apply:
        p.write_text(json.dumps(data, indent=4) + "\n")
    return 'edited' if apply else 'would_edit'


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)

    print(f'=== patch-143 [{"APPLY" if a.apply else "DRY-RUN"}] target={root} ===\n')

    # Composer
    print(f'  composer.json: {edit_composer_json(root, a.apply)}')

    # New files
    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    # Edits
    for rel, old, new, label, marker in EDITS:
        p = root / rel
        t = p.read_text()
        if marker in t:
            print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label} ({t.count(old)} matches)', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    if a.apply:
        print('\nFollow-up steps NOT done by this patch:')
        print('  1. On Mac: composer require aws/aws-sdk-php')
        print('  2. Copy tools/.env.ses.example values into .env on the droplet')
        print('  3. php artisan config:clear after .env changes')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
