<?php
// MARKER-EMAIL-LEDGER

namespace App\Services;

use App\Models\PlatformSettings;
use App\Models\Tenant\TenantEmailLedgerEntry;

/**
 * Metering around every tenant email send.
 *
 * Contract: begin() writes a pending row before the send; markSent()
 * confirms it; void() cancels it on failure. All three are null-safe and
 * never throw — a ledger failure must not stop a customer's receipt.
 * (The campaign pipeline, phase 3, inverts that: no row, no send.)
 */
class EmailLedger
{
    public static function begin(
        string $tenantId,
        string $kind,
        string $toEmail,
        ?string $templateKey = null,
        ?int $campaignId = null
    ): ?TenantEmailLedgerEntry {
        try {
            return TenantEmailLedgerEntry::create([
                'tenant_id'    => $tenantId,
                'kind'         => $kind,
                'template_key' => $templateKey,
                'to_email'     => strtolower(trim($toEmail)),
                'rate'         => self::rate(),
                'stream'       => $kind === 'campaign'
                                    ? (self::broadcastStream() ?? 'outbound')
                                    : 'outbound',
                'status'       => TenantEmailLedgerEntry::STATUS_PENDING,
                'campaign_id'  => $campaignId,
            ]);
        } catch (\Throwable $e) {
            // Loud, because this is unbilled mail — but never blocking.
            logger()->error('email_ledger.begin_failed', [
                'tenant_id' => $tenantId,
                'kind'      => $kind,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function markSent(?TenantEmailLedgerEntry $entry): void
    {
        if (! $entry) return;
        try {
            $entry->update(['status' => TenantEmailLedgerEntry::STATUS_SENT]);
        } catch (\Throwable $e) {
            logger()->error('email_ledger.mark_sent_failed', ['id' => $entry->id, 'error' => $e->getMessage()]);
        }
    }

    public static function void(?TenantEmailLedgerEntry $entry): void
    {
        if (! $entry) return;
        try {
            $entry->update(['status' => TenantEmailLedgerEntry::STATUS_VOIDED]);
        } catch (\Throwable $e) {
            logger()->error('email_ledger.void_failed', ['id' => $entry->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Classify a template key into a billing kind. Campaigns never come
     * through template keys — the campaign pipeline passes 'campaign'
     * explicitly.
     */
    public static function kindFor(?string $templateKey): string
    {
        $k = strtolower((string) $templateKey);
        if ($k === '') return 'other';

        if (str_contains($k, 'reminder') || str_contains($k, 'review')
            || str_contains($k, 'follow') || str_contains($k, 'pickup')
            || str_contains($k, 'ready') || str_contains($k, 'windows')) {
            return 'reminder';
        }
        if (str_contains($k, 'receipt') || str_contains($k, 'confirmation')
            || str_contains($k, 'invoice') || str_contains($k, 'gift_card')
            || str_contains($k, 'booking')) {
            return 'receipt';
        }
        if (str_contains($k, 'schedule') || str_contains($k, 'announcement')
            || str_contains($k, 'timeclock')) {
            return 'staff';
        }
        if (str_contains($k, 'inbox') || str_contains($k, 'reply')) {
            return 'reply';
        }
        return 'other';
    }

    /** Dollars per email, master-admin editable, never hardcoded at call sites. */
    public static function rate(): float
    {
        return (float) (PlatformSettings::current()->email_rate ?? 0.002);
    }

    /** Postmark broadcast stream ID, or null when not yet configured. */
    public static function broadcastStream(): ?string
    {
        $s = trim((string) (PlatformSettings::current()->email_broadcast_stream ?? ''));
        return $s !== '' ? $s : null;
    }
}
