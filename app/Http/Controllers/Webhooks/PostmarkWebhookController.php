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
    /**
     * MARKER-STREAM-ASSERT — types that mean the MAILBOX is gone, not that
     * one sender was refused. A mailbox that does not exist does not exist
     * for every shop, so these suppress platform-wide on first hit instead
     * of waiting for the 3-tenant escalation.
     */
    protected array $platformWideBounceTypes = [
        'HardBounce',
        'BadEmailAddress',
        'ManuallyDeactivated',
    ];

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

        // MARKER-CAMPAIGN-RESULTS — campaign engagement.
        if ($recordType === 'Open' || $recordType === 'Click') {
            return $this->handleEngagement($recordType, $payload);
        }

        // Delivery / SubscriptionChange etc. — acknowledged, not acted on.
        Log::info('[Postmark] Ignored RecordType', ['type' => $recordType]);
        return response('OK', 200);
    }

    /**
     * MARKER-CAMPAIGN-RESULTS — map an Open/Click back to its recipient row
     * via the send_id metadata, then recompute the campaign's counters from
     * the rows. Recomputing (not incrementing) makes replayed webhooks safe.
     */
    protected function handleEngagement(string $type, array $payload)
    {
        $meta   = $payload['Metadata'] ?? [];
        $sendId = $meta['send_id'] ?? null;
        if (! $sendId) {
            return response('OK', 200);
        }

        $row = \App\Models\Tenant\TenantCampaignSend::find($sendId);
        if (! $row) {
            return response('OK', 200);
        }

        if ($type === 'Open') {
            $row->update([
                'open_count' => min(65000, (int) $row->open_count + 1),
                'opened_at'  => $row->opened_at ?: now(),
            ]);
        } else {
            $row->update([
                'click_count' => min(65000, (int) $row->click_count + 1),
                'clicked_at'  => $row->clicked_at ?: now(),
                // A click implies an open even when the open pixel was blocked.
                'opened_at'   => $row->opened_at ?: now(),
            ]);
        }

        $this->recountCampaign((string) $row->campaign_id);

        return response('OK', 200);
    }

    /** Counters are always derived from the send rows, never incremented. */
    protected function recountCampaign(string $campaignId): void
    {
        $campaign = \App\Models\Tenant\TenantCampaign::find($campaignId);
        if (! $campaign) return;

        $rows = \App\Models\Tenant\TenantCampaignSend::where('campaign_id', $campaignId);

        $campaign->update([
            'total_sent'    => (clone $rows)->where('status', 'sent')->count(),
            'total_opened'  => (clone $rows)->whereNotNull('opened_at')->count(),
            'total_clicked' => (clone $rows)->whereNotNull('clicked_at')->count(),
        ]);
    }

    /**
     * Bounce: log the event; suppress immediately for permanent types; escalate
     * to platform-wide if the same address has bounced from 3+ tenants.
     */
    protected function handleBounce(array $payload)
    {
        // MARKER-CAMPAIGN-RESULTS — if this bounce belongs to a campaign send,
        // record it on that row as well. Suppression handling continues below
        // unchanged; this only adds per-campaign visibility.
        $bounceSendId = $payload['Metadata']['send_id'] ?? null;
        if ($bounceSendId) {
            $bounceRow = \App\Models\Tenant\TenantCampaignSend::find($bounceSendId);
            if ($bounceRow) {
                $bounceRow->update([
                    'status'        => 'bounced',
                    'error_message' => (string) ($payload['Description'] ?? $payload['Type'] ?? 'bounced'),
                ]);
                $this->recountCampaign((string) $bounceRow->campaign_id);
            }
        }

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

            // MARKER-STREAM-ASSERT — mailbox-gone types block everywhere at
            // once (the tenant row above stays for their suppression page);
            // sender-specific types keep the 3-tenant escalation.
            if (in_array($type, $this->platformWideBounceTypes, true)) {
                $this->suppress(null, $email, 'bounce', $type, $msgId, $detail);
            } else {
                $this->maybeEscalateToPlatform($email);
            }
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
