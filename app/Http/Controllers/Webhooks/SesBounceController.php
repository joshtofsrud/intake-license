<?php
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
