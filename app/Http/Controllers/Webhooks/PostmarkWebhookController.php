<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantEmailSuppression;
use App\Models\Tenant\TenantEmailBounceEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-PATCH-201 — Postmark bounce / spam-complaint webhook.
 *
 * Replaces the SES/SNS path (SesBounceController) now that transactional mail
 * sends through Postmark. Postmark posts a flat JSON body (no SNS envelope, no
 * subscription handshake) with a RecordType discriminator:
 *   - RecordType "Bounce"        → Type HardBounce / SoftBounce / Transient / etc.
 *   - RecordType "SpamComplaint" → always platform-wide, immediate.
 *
 * Tenant mapping: EmailService sets Postmark Metadata `tenant_id` on every
 * tenant-originated send (via the X-PM-Metadata-tenant_id SMTP header), which
 * Postmark echoes back here as payload["Metadata"]["tenant_id"]. Platform mail
 * (welcome emails, etc.) has no tenant_id → null (platform-scoped).
 *
 * Suppression model is shared with the SES path: log every event; suppress
 * permanent/hard bounces immediately; escalate to platform-wide if the same
 * address bounces from 3+ tenants; complaints are always platform-wide.
 *
 * Security: basic-auth on the webhook URL (configured in Postmark) + optional
 * Postmark source-IP allowlist at the edge. CSRF-exempt (external POST).
 */
class PostmarkWebhookController extends Controller
{
    /**
     * Postmark bounce Types that are permanent enough to suppress on first hit.
     * Soft/Transient/etc. are logged only (a follow-up can promote after N).
     */
    protected array $suppressOnBounceTypes = [
        'HardBounce',
        'BadEmailAddress',
        'ManuallyDeactivated',
        'Blocked',
        'SpamNotification',
        'Unsubscribe',
        'AddressChange',
    ];

    public function handle(Request $request)
    {
        $payload = $request->json()->all();
        $recordType = $payload['RecordType'] ?? null;

        if ($recordType === 'Bounce') {
            return $this->handleBounce($payload);
        }
        if ($recordType === 'SpamComplaint') {
            return $this->handleComplaint($payload);
        }

        // Delivery / Open / Click / SubscriptionChange etc. — acknowledged, not acted on.
        Log::info('[Postmark] Ignored RecordType', ['type' => $recordType]);
        return response('OK', 200);
    }

    /**
     * Bounce: log the event; suppress immediately for permanent types; escalate
     * to platform-wide if the same address has bounced from 3+ tenants.
     */
    protected function handleBounce(array $payload)
    {
        $email    = strtolower(trim($payload['Email'] ?? ''));
        $type     = $payload['Type'] ?? null;          // e.g. HardBounce
        $msgId    = $payload['MessageID'] ?? null;
        $detail   = $payload['Details'] ?? ($payload['Description'] ?? null);
        $tenantId = $this->tenantIdFromMetadata($payload);

        if ($email === '') {
            Log::warning('[Postmark] Bounce with no email', ['message_id' => $msgId]);
            return response('OK', 200);
        }

        TenantEmailBounceEvent::create([
            'tenant_id'         => $tenantId,
            'email'             => $email,
            'event_type'        => 'bounce',
            'bounce_type'       => $type,
            'bounce_subtype'    => $payload['Name'] ?? null,
            'source_message_id' => $msgId,
            'payload'           => $payload,
        ]);

        if (in_array($type, $this->suppressOnBounceTypes, true)) {
            $this->suppress($tenantId, $email, 'bounce', $type, $msgId, $detail);
            $this->maybeEscalateToPlatform($email);
        }
        // Soft/Transient bounces are logged only.

        return response('OK', 200);
    }

    /**
     * Spam complaint: always platform-wide and immediate.
     */
    protected function handleComplaint(array $payload)
    {
        $email  = strtolower(trim($payload['Email'] ?? ''));
        $msgId  = $payload['MessageID'] ?? null;

        if ($email === '') {
            return response('OK', 200);
        }

        $tenantId = $this->tenantIdFromMetadata($payload);

        TenantEmailBounceEvent::create([
            'tenant_id'         => $tenantId,
            'email'             => $email,
            'event_type'        => 'complaint',
            'bounce_type'       => 'SpamComplaint',
            'bounce_subtype'    => null,
            'source_message_id' => $msgId,
            'payload'           => $payload,
        ]);

        // Complaints are always platform-wide (tenant_id = null).
        $this->suppress(null, $email, 'complaint', 'SpamComplaint', $msgId, null);

        return response('OK', 200);
    }

    /**
     * Pull tenant_id from Postmark Metadata. EmailService sets it on every
     * tenant send; platform mail omits it → null. Validates the tenant exists.
     */
    protected function tenantIdFromMetadata(array $payload): ?string
    {
        $meta = $payload['Metadata'] ?? [];
        $value = is_array($meta) ? trim((string) ($meta['tenant_id'] ?? '')) : '';
        if ($value !== '' && Tenant::where('id', $value)->exists()) {
            return $value;
        }
        return null;
    }

    /**
     * Upsert a suppression row. Idempotent. (Mirrors SesBounceController.)
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
        Log::info('[Postmark] Suppressed', [
            'tenant_id' => $tenantId,
            'email'     => $email,
            'reason'    => $reason,
        ]);
    }

    /**
     * Promote a per-tenant bounce to platform-wide if the same email has been
     * suppressed at 3+ different tenants. (Mirrors SesBounceController.)
     */
    protected function maybeEscalateToPlatform(string $email): void
    {
        $email = strtolower(trim($email));
        $tenantCount = TenantEmailSuppression::where('email', $email)
            ->whereNotNull('tenant_id')
            ->distinct('tenant_id')
            ->count('tenant_id');

        if ($tenantCount >= 3) {
            TenantEmailSuppression::firstOrCreate(
                ['tenant_id' => null, 'email' => $email],
                [
                    'reason'        => 'bounce',
                    'subtype'       => 'PlatformEscalation',
                    'notes'         => "Escalated to platform-wide after bouncing from {$tenantCount} tenants",
                    'suppressed_at' => now(),
                ]
            );
            Log::warning('[Postmark] Escalated to platform-wide suppression', [
                'email'        => $email,
                'tenant_count' => $tenantCount,
            ]);
        }
    }
}
