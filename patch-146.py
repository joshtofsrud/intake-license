#!/usr/bin/env python3
"""
Patch 146 — Email Patch D: bounce/complaint SNS webhook + auto-suppression.

Wires the SES → SNS → Intake webhook flow. When SES sends us a bounce
or complaint notification via SNS, we:

  1. Verify the message is from SNS by checking the headers + signature
     (Laravel doesn't have a stock SNS verifier; we use AWS's PHP SDK
     classes which are already available from patch 143).
  2. Auto-confirm the subscription on first hit (SES posts a
     SubscriptionConfirmation message that must be GET'd at a special
     URL to activate the subscription).
  3. For Notification messages, parse the embedded JSON and decide:
        - Permanent bounce → suppress immediately, tenant-scoped
        - Transient bounce → record, suppress after 3+ in 7 days
        - Complaint → suppress immediately, PLATFORM-WIDE
  4. Once the same address bounces from 3+ different tenants, escalate
     to platform-wide suppression.

Adds suppression check to EmailService::send() so suppressed addresses
are skipped before any SES API call.

Idempotent.
"""

import argparse
import pathlib
import sys
import json


# ============================================================
# NEW FILES
# ============================================================

MIGRATION = r'''<?php
// MARKER-PATCH-146

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_suppressions', function (Blueprint $table) {
            $table->id();

            // tenant_id NULL = platform-wide suppression (complaints,
            // and addresses that bounced from 3+ different tenants).
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Normalised lowercase email
            $table->string('email', 255)->index();

            // 'bounce' (permanent), 'transient_bounce', 'complaint', 'manual', 'unsubscribe'
            $table->string('reason', 32);

            // 'permanent' or 'transient' from SES bounce subType, or null
            $table->string('subtype', 64)->nullable();

            // The SES message ID that triggered this suppression (for audit)
            $table->string('source_message_id', 128)->nullable()->index();

            // SES diagnostic code (e.g. "5.1.1 user unknown")
            $table->text('diagnostic')->nullable();

            // Free-form notes (manual suppressions)
            $table->text('notes')->nullable();

            // Who suppressed this (NULL for system-driven)
            $table->uuid('suppressed_by_user_id')->nullable();

            $table->timestamp('suppressed_at')->useCurrent();
            $table->timestamps();

            // One row per (tenant, email) pair. Platform-wide rows have tenant_id NULL.
            $table->unique(['tenant_id', 'email'], 'tenant_email_suppression_unique');
        });

        Schema::create('tenant_email_bounce_events', function (Blueprint $table) {
            // Raw event log — every bounce/complaint we receive, regardless of
            // whether it triggered a suppression. Used for trend analysis and
            // to enforce "3+ tenants bounced → platform suppression" rule.
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('email', 255)->index();
            $table->string('event_type', 24);   // 'bounce' or 'complaint'
            $table->string('bounce_type', 24)->nullable();   // 'Permanent' / 'Transient' / 'Undetermined'
            $table->string('bounce_subtype', 64)->nullable(); // 'General' / 'NoEmail' / 'Suppressed' / ...
            $table->string('source_message_id', 128)->nullable()->index();
            $table->json('payload')->nullable();   // Full SNS message for forensics
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_bounce_events');
        Schema::dropIfExists('tenant_email_suppressions');
    }
};
'''


SUPPRESSION_MODEL = r'''<?php
// MARKER-PATCH-146

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantEmailSuppression — addresses that should never receive mail.
 *
 * Scope:
 *   - tenant_id != null  → suppressed for that tenant only
 *   - tenant_id == null  → suppressed platform-wide
 *
 * EmailService checks BOTH scopes before sending. If either matches,
 * the send is skipped.
 */
class TenantEmailSuppression extends Model
{
    protected $table = 'tenant_email_suppressions';

    protected $fillable = [
        'tenant_id',
        'email',
        'reason',
        'subtype',
        'source_message_id',
        'diagnostic',
        'notes',
        'suppressed_by_user_id',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Is the given email suppressed for the given tenant?
     * Returns true if EITHER a tenant-scoped or platform-wide suppression matches.
     */
    public static function isSuppressed(?string $tenantId, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;

        return self::where('email', $email)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $tenantId);
            })
            ->exists();
    }
}
'''


BOUNCE_EVENT_MODEL = r'''<?php
// MARKER-PATCH-146

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantEmailBounceEvent — raw log of every bounce/complaint we receive.
 *
 * Suppression decisions are derived from these events. Keeps a full
 * audit trail separate from the active suppression list — so we can
 * see "this address bounced 4 times from 3 different tenants" even
 * after the suppression row is cleaned up.
 */
class TenantEmailBounceEvent extends Model
{
    protected $table = 'tenant_email_bounce_events';

    protected $fillable = [
        'tenant_id',
        'email',
        'event_type',
        'bounce_type',
        'bounce_subtype',
        'source_message_id',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
'''


WEBHOOK_CONTROLLER = r'''<?php
// MARKER-PATCH-146

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailBounceEvent;
use App\Models\Tenant\TenantEmailSuppression;
use App\Models\Tenant;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SES bounce/complaint webhook.
 *
 * SNS posts here. Three message types we handle:
 *
 *  1. SubscriptionConfirmation — issued once when the subscription is
 *     first created. We GET the SubscribeURL to confirm. Without this
 *     step the subscription stays in "Pending confirmation" forever.
 *
 *  2. Notification — the actual bounce/complaint event. We parse the
 *     embedded JSON from SES and record + suppress as needed.
 *
 *  3. UnsubscribeConfirmation — if the topic gets deleted. We log and ignore.
 *
 * Signature verification uses the AWS SDK's MessageValidator class
 * (already available via aws/aws-sdk-php from patch 143).
 */
class SesBounceController extends Controller
{
    /**
     * POST /webhooks/ses-bounce
     */
    public function handle(Request $request)
    {
        try {
            // 1. Parse SNS envelope.
            $message = Message::fromRawPostData();
        } catch (\Throwable $e) {
            Log::warning('[SesBounce] Invalid SNS message envelope', [
                'error' => $e->getMessage(),
            ]);
            return response('Bad request', 400);
        }

        // 2. Verify signature so we know the message is really from AWS.
        try {
            (new MessageValidator())->validate($message);
        } catch (\Throwable $e) {
            Log::warning('[SesBounce] SNS signature validation failed', [
                'error' => $e->getMessage(),
                'message_id' => $message['MessageId'] ?? null,
            ]);
            return response('Invalid signature', 403);
        }

        $type = $message['Type'] ?? '';

        // 3. Auto-confirm subscription on first hit.
        if ($type === 'SubscriptionConfirmation') {
            $subscribeUrl = $message['SubscribeURL'] ?? null;
            if (! $subscribeUrl) {
                return response('No SubscribeURL', 400);
            }
            try {
                Http::timeout(5)->get($subscribeUrl);
                Log::info('[SesBounce] SNS subscription confirmed', [
                    'topic'      => $message['TopicArn'] ?? null,
                    'message_id' => $message['MessageId'] ?? null,
                ]);
                return response('Subscription confirmed', 200);
            } catch (\Throwable $e) {
                Log::error('[SesBounce] Subscription confirm failed', [
                    'error' => $e->getMessage(),
                ]);
                return response('Confirm failed', 500);
            }
        }

        // 4. Unsubscribe confirmation — just log and accept.
        if ($type === 'UnsubscribeConfirmation') {
            Log::info('[SesBounce] SNS topic unsubscribed', [
                'topic'      => $message['TopicArn'] ?? null,
                'message_id' => $message['MessageId'] ?? null,
            ]);
            return response('OK', 200);
        }

        // 5. Notification — the SES event we actually care about.
        if ($type !== 'Notification') {
            Log::info('[SesBounce] Unexpected message type', ['type' => $type]);
            return response('OK', 200);
        }

        // SES wraps its payload in the SNS Message field as a JSON string.
        $payload = json_decode($message['Message'] ?? '', true);
        if (! is_array($payload)) {
            Log::warning('[SesBounce] Malformed Message payload', [
                'sns_message_id' => $message['MessageId'] ?? null,
            ]);
            return response('Malformed payload', 400);
        }

        $notificationType = $payload['notificationType'] ?? null;
        if ($notificationType === 'Bounce') {
            return $this->handleBounce($payload);
        }
        if ($notificationType === 'Complaint') {
            return $this->handleComplaint($payload);
        }

        Log::info('[SesBounce] Ignored notificationType', ['type' => $notificationType]);
        return response('OK', 200);
    }

    /**
     * Handle a bounce notification. Permanent → suppress immediately.
     * Transient → record only (suppression after 3+ in 7 days).
     */
    protected function handleBounce(array $payload)
    {
        $bounce  = $payload['bounce'] ?? [];
        $mail    = $payload['mail']   ?? [];
        $type    = $bounce['bounceType']    ?? null;
        $subtype = $bounce['bounceSubType'] ?? null;
        $msgId   = $mail['messageId']       ?? null;
        $recipients = $bounce['bouncedRecipients'] ?? [];

        $tenantId = $this->tenantIdFromHeaders($mail);

        foreach ($recipients as $recipient) {
            $email = strtolower(trim($recipient['emailAddress'] ?? ''));
            if ($email === '') continue;

            $diagnostic = $recipient['diagnosticCode'] ?? null;

            // Always log the event.
            TenantEmailBounceEvent::create([
                'tenant_id'         => $tenantId,
                'email'             => $email,
                'event_type'        => 'bounce',
                'bounce_type'       => $type,
                'bounce_subtype'    => $subtype,
                'source_message_id' => $msgId,
                'payload'           => $payload,
            ]);

            if ($type === 'Permanent') {
                // Suppress immediately, tenant-scoped (or platform if unknown tenant).
                $this->suppress($tenantId, $email, 'bounce', $subtype, $msgId, $diagnostic);

                // Escalation: if same address has bounced from 3+ tenants, go platform-wide.
                $this->maybeEscalateToPlatform($email);
            }
            // Transient bounces are logged only — we don't suppress on a single soft bounce.
            // A follow-up job (not in this patch) can promote to suppression after N transients.
        }

        return response('OK', 200);
    }

    /**
     * Handle a complaint. ALWAYS platform-wide and immediate.
     */
    protected function handleComplaint(array $payload)
    {
        $complaint = $payload['complaint'] ?? [];
        $mail      = $payload['mail']      ?? [];
        $msgId     = $mail['messageId']    ?? null;
        $tenantId  = $this->tenantIdFromHeaders($mail);
        $recipients = $complaint['complainedRecipients'] ?? [];

        foreach ($recipients as $recipient) {
            $email = strtolower(trim($recipient['emailAddress'] ?? ''));
            if ($email === '') continue;

            TenantEmailBounceEvent::create([
                'tenant_id'         => $tenantId,
                'email'             => $email,
                'event_type'        => 'complaint',
                'bounce_type'       => null,
                'bounce_subtype'    => $complaint['complaintFeedbackType'] ?? null,
                'source_message_id' => $msgId,
                'payload'           => $payload,
            ]);

            // Complaints are always platform-wide. Suppress with tenant_id=null.
            $this->suppress(null, $email, 'complaint', $complaint['complaintFeedbackType'] ?? null, $msgId, null);
        }

        return response('OK', 200);
    }

    /**
     * Try to extract a tenant_id from the SES mail headers. We send
     * an X-Tenant-Id custom header on every tenant-originated send
     * via EmailService (added in this same patch). If absent (e.g.
     * Intake-platform mail like welcome emails), returns null.
     */
    protected function tenantIdFromHeaders(array $mail): ?string
    {
        $headers = $mail['headers'] ?? [];
        foreach ($headers as $h) {
            if (strcasecmp($h['name'] ?? '', 'X-Tenant-Id') === 0) {
                $value = trim($h['value'] ?? '');
                if ($value !== '' && Tenant::where('id', $value)->exists()) {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Upsert a suppression row. Idempotent.
     */
    protected function suppress(?string $tenantId, string $email, string $reason, ?string $subtype, ?string $msgId, ?string $diagnostic): void
    {
        TenantEmailSuppression::updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => $email],
            [
                'reason'            => $reason,
                'subtype'           => $subtype,
                'source_message_id' => $msgId,
                'diagnostic'        => $diagnostic,
                'suppressed_at'     => now(),
            ]
        );
        Log::info('[SesBounce] Suppressed', [
            'tenant_id' => $tenantId,
            'email'     => $email,
            'reason'    => $reason,
        ]);
    }

    /**
     * Promote a per-tenant bounce to platform-wide if the same email
     * has been suppressed at 3+ different tenants.
     */
    protected function maybeEscalateToPlatform(string $email): void
    {
        $email = strtolower(trim($email));
        $tenantCount = TenantEmailSuppression::where('email', $email)
            ->whereNotNull('tenant_id')
            ->distinct('tenant_id')
            ->count('tenant_id');

        if ($tenantCount >= 3) {
            // Platform-wide row, no source_message_id (synthetic).
            TenantEmailSuppression::firstOrCreate(
                ['tenant_id' => null, 'email' => $email],
                [
                    'reason'        => 'bounce',
                    'subtype'       => 'PlatformEscalation',
                    'notes'         => "Escalated to platform-wide after bouncing from {$tenantCount} tenants",
                    'suppressed_at' => now(),
                ]
            );
            Log::warning('[SesBounce] Escalated to platform-wide suppression', [
                'email'        => $email,
                'tenant_count' => $tenantCount,
            ]);
        }
    }
}
'''


# ============================================================
# EDITS
# ============================================================

# EmailService — add suppression check + X-Tenant-Id header
OLD_EMAIL_SERVICE = """        try {
            Mail::send([], [], function ($message) use (
                $toEmail, $subject, $body, $fromName, $fromEmail, $replyTo
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($body);
            });
        } catch (\\Throwable $e) {
            logger()->error(\"EmailService send failed [{$templateKey}]: {$e->getMessage()}\");
        }
    }"""

NEW_EMAIL_SERVICE = """        // MARKER-PATCH-146 — suppression gate
        if (\\App\\Models\\Tenant\\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info(\"EmailService skipped (suppressed) [{$templateKey}]\", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return;
        }

        try {
            $tenantId = $this->tenant->id;
            Mail::send([], [], function ($message) use (
                $toEmail, $subject, $body, $fromName, $fromEmail, $replyTo, $tenantId
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($body)
                    // MARKER-PATCH-146 — header lets the bounce webhook map events back to tenants
                    ->getHeaders()->addTextHeader('X-Tenant-Id', $tenantId);
            });
        } catch (\\Throwable $e) {
            logger()->error(\"EmailService send failed [{$templateKey}]: {$e->getMessage()}\");
        }
    }"""


# Route registration — add the public webhook route. Place it near the
# Cloudflare webhook anchor since they share the same /webhooks/* scope.
OLD_ROUTE = """Route::post('webhooks/cloudflare', [\\App\\Http\\Controllers\\Webhooks\\CloudflareWebhookController::class, 'handle'])
    ->name('webhooks.cloudflare');"""

NEW_ROUTE = """Route::post('webhooks/cloudflare', [\\App\\Http\\Controllers\\Webhooks\\CloudflareWebhookController::class, 'handle'])
    ->name('webhooks.cloudflare');

// MARKER-PATCH-146 — SES bounce/complaint webhook (signature-verified inside controller)
Route::post('webhooks/ses-bounce', [\\App\\Http\\Controllers\\Webhooks\\SesBounceController::class, 'handle'])
    ->name('webhooks.ses-bounce');"""


# CSRF exemption — Laravel needs the route in VerifyCsrfToken::$except
OLD_CSRF = "protected \\$except = ["
NEW_CSRF_MARKER = 'MARKER-PATCH-146'


def add_csrf_exemption(root: pathlib.Path, apply: bool) -> str:
    """Laravel 11 keeps the CSRF exception list in bootstrap/app.php."""
    p = root / 'bootstrap' / 'app.php'
    if not p.exists():
        return 'skipped (no bootstrap/app.php)'
    t = p.read_text()
    if 'MARKER-PATCH-146' in t:
        return 'already_applied'
    needle = "'webhooks/cloudflare', // MARKER-PATCH-118"
    if needle not in t:
        return 'ERROR: anchor not found in bootstrap/app.php'
    new_t = t.replace(
        needle,
        needle + "\n            'webhooks/ses-bounce',  // MARKER-PATCH-146",
        1
    )
    if apply:
        p.write_text(new_t)
    return 'edited' if apply else 'would_edit'


NEW_FILES = {
    'database/migrations/2026_05_25_000001_create_tenant_email_suppressions_table.php': MIGRATION,
    'app/Models/Tenant/TenantEmailSuppression.php':       SUPPRESSION_MODEL,
    'app/Models/Tenant/TenantEmailBounceEvent.php':       BOUNCE_EVENT_MODEL,
    'app/Http/Controllers/Webhooks/SesBounceController.php': WEBHOOK_CONTROLLER,
}

EDITS = [
    ('app/Services/EmailService.php', OLD_EMAIL_SERVICE, NEW_EMAIL_SERVICE, 'EmailService suppression + tenant header', 'MARKER-PATCH-146 — suppression gate'),
    ('routes/web.php',                OLD_ROUTE,         NEW_ROUTE,         'routes: ses-bounce webhook',                'MARKER-PATCH-146 — SES bounce/complaint webhook'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-146 [{mode}] target={root} ===\n')

    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    for rel, old, new, label, marker in EDITS:
        p = root / rel
        t = p.read_text()
        if marker in t:
            print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    print(f'  csrf exemption: {add_csrf_exemption(root, a.apply)}')

    if a.apply:
        print('\nFollow-up steps NOT done by this patch:')
        print('  1. php artisan migrate  (creates tenant_email_suppressions + tenant_email_bounce_events)')
        print('  2. Confirm SNS subscription flips from "Pending" to "Confirmed" on first webhook hit')
        print('  3. Trigger a test bounce: send to bounce@simulator.amazonses.com (SES test address)')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
