<?php

namespace App\Services;

use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Services\Sms\SegmentCounter;

/**
 * MARKER-SMS-METER — the SMS half of the ledger.
 *
 * Same table and same contract as EmailLedger: a row is written BEFORE the
 * send and marked sent afterwards, so a crash mid-send leaves a pending row
 * rather than an unbilled message. Metering never blocks a send — a text that
 * cannot be metered still goes out, loudly logged.
 */
class MessageLedger
{
    public static function rate(): float
    {
        $s = PlatformSettings::query()->first();
        return (float) ($s->sms_rate ?? 0.014);
    }

    public static function mmsMultiplier(): int
    {
        $s = PlatformSettings::query()->first();
        return max(1, (int) ($s->mms_multiplier ?? 3));
    }

    /**
     * A shop using its own Twilio credentials pays Twilio directly. Their
     * messages are still recorded — volume is worth seeing — at zero rate.
     */
    public static function isByo(Tenant $tenant): bool
    {
        return (bool) ($tenant->twilio_account_sid && $tenant->twilio_auth_token);
    }

    public static function begin(
        Tenant $tenant,
        string $kind,
        string $toPhone,
        string $body,
        bool $isMms = false
    ): ?TenantEmailLedgerEntry {
        try {
            $segments = SegmentCounter::segments($body);
            $rate     = self::isByo($tenant) ? 0.0 : self::rate();
            if ($isMms) {
                $rate     = $rate * self::mmsMultiplier();
                $segments = 1; // carriers bill a picture message as one unit
            }

            return TenantEmailLedgerEntry::create([
                'tenant_id' => $tenant->id,
                'kind'      => $kind,
                'channel'   => 'sms',
                'to_email'  => null,
                'to_phone'  => substr($toPhone, 0, 32),
                'rate'      => $rate,
                'segments'  => $segments,
                'stream'    => 'sms',
                'status'    => TenantEmailLedgerEntry::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            logger()->error('message_ledger.begin_failed', [
                'tenant_id' => $tenant->id,
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
            logger()->error('message_ledger.mark_sent_failed', ['id' => $entry->id, 'error' => $e->getMessage()]);
        }
    }

    public static function void(?TenantEmailLedgerEntry $entry): void
    {
        if (! $entry) return;
        try {
            $entry->update(['status' => TenantEmailLedgerEntry::STATUS_VOIDED]);
        } catch (\Throwable $e) {
            logger()->error('message_ledger.void_failed', ['id' => $entry->id, 'error' => $e->getMessage()]);
        }
    }

    /** Segment total for a window — what the statement will show. */
    public static function monthToDate(string $tenantId): array
    {
        $row = TenantEmailLedgerEntry::where('tenant_id', $tenantId)
            ->where('channel', 'sms')
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as messages, SUM(segments) as segments, SUM(rate * segments) as spend')
            ->first();

        return [
            'messages' => (int) ($row->messages ?? 0),
            'segments' => (int) ($row->segments ?? 0),
            'spend'    => (float) ($row->spend ?? 0),
        ];
    }
}
