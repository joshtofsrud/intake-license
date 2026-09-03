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
            $isFree = self::usedThisMonth($tenantId) < self::freeAllowance($tenantId);

            $entry = TenantEmailLedgerEntry::create([
                'tenant_id'    => $tenantId,
                'kind'         => $kind,
                'template_key' => $templateKey,
                'to_email'     => strtolower(trim($toEmail)),
                // MARKER-EMAIL-RATES — marketing and transactional are priced
                // differently, and the first N of the month are free.
                'rate'         => $isFree ? 0 : self::rateFor($kind),
                'is_free'      => $isFree,
                'stream'       => $kind === 'campaign'
                                    ? (self::broadcastStream() ?? 'outbound')
                                    : 'outbound',
                'status'       => TenantEmailLedgerEntry::STATUS_PENDING,
                'campaign_id'  => $campaignId,
            ]);

            self::noteWritten($tenantId);
            return $entry;
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

    // ------------------------------------------------------------------
    // MARKER-EMAIL-BILLING — spend and caps.
    // Spend always SUMS THE STAMPED RATE on each row. Never count × current
    // rate: a rate change would silently rewrite last month's invoice.
    // ------------------------------------------------------------------

    /** Month-to-date spend for a tenant, per kind and total. */
    public static function monthToDate(string $tenantId): array
    {
        $since = now()->startOfMonth();

        $rows = TenantEmailLedgerEntry::where('tenant_id', $tenantId)
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->selectRaw('kind, COUNT(*) as n, SUM(rate) as spend')
            ->groupBy('kind')
            ->get();

        $byKind = [];
        $total  = 0.0;
        $count  = 0;

        foreach ($rows as $r) {
            $byKind[$r->kind] = ['count' => (int) $r->n, 'spend' => (float) $r->spend];
            $total += (float) $r->spend;
            $count += (int) $r->n;
        }

        return [
            'since'     => $since,
            'by_kind'   => $byKind,
            'total'     => $total,
            'count'     => $count,
            'marketing' => $byKind['campaign']['spend'] ?? 0.0,
        ];
    }

    /**
     * Cap state for marketing spend. 'none' when uncapped; otherwise the
     * cap, what's spent against it, and whether it's been reached.
     */
    public static function capState(\App\Models\Tenant $tenant): array
    {
        $capCents = $tenant->email_spend_cap_cents;
        $spent    = self::monthToDate($tenant->id)['marketing'];

        if ($capCents === null) {
            return ['capped' => false, 'cap' => null, 'spent' => $spent, 'reached' => false, 'remaining' => null];
        }

        $cap = $capCents / 100;

        return [
            'capped'    => true,
            'cap'       => $cap,
            'spent'     => $spent,
            'reached'   => $spent >= $cap,
            'remaining' => max(0, $cap - $spent),
        ];
    }

    /** Dollars per email, master-admin editable, never hardcoded at call sites. */
    /** MARKER-EMAIL-RATES — transactional: receipts, reminders, confirmations. */
    public static function rate(): float
    {
        return (float) (PlatformSettings::current()->email_rate ?? 0.002);
    }

    /** MARKER-EMAIL-RATES — marketing: campaigns a shop chooses to send. */
    public static function marketingRate(): float
    {
        return (float) (PlatformSettings::current()->email_rate_marketing ?? 0.0035);
    }

    /** The rate a given kind of mail is charged at. */
    public static function rateFor(string $kind): float
    {
        return $kind === 'campaign' ? self::marketingRate() : self::rate();
    }

    /**
     * How many free emails this shop gets each month. Memoised: begin() runs
     * once per recipient, and a campaign has thousands.
     */
    private static array $allowance = [];

    public static function freeAllowance(string $tenantId): int
    {
        if (! array_key_exists($tenantId, self::$allowance)) {
            $own = \App\Models\Tenant::whereKey($tenantId)->value('email_free_monthly');
            self::$allowance[$tenantId] = $own !== null
                ? (int) $own                                            // 0 means deliberately none
                : (int) (PlatformSettings::current()->email_free_monthly ?? 0);
        }
        return self::$allowance[$tenantId];
    }

    /**
     * MARKER-EMAIL-RATES — how many of this month's emails have been metered.
     *
     * Seeded from the database once per process and incremented in memory, so a
     * 1,500-recipient campaign does not run 1,500 COUNT queries. Two workers
     * starting at the same moment can each seed before the other writes, which
     * may grant a few extra free emails — deliberately preferred to locking
     * every send.
     */
    private static array $monthCount = [];

    public static function usedThisMonth(string $tenantId): int
    {
        $key = $tenantId . ':' . now()->format('Y-m');
        if (! array_key_exists($key, self::$monthCount)) {
            self::$monthCount[$key] = TenantEmailLedgerEntry::where('tenant_id', $tenantId)
                ->where('channel', 'email')
                ->whereIn('status', [TenantEmailLedgerEntry::STATUS_SENT, TenantEmailLedgerEntry::STATUS_PENDING])
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();
        }
        return self::$monthCount[$key];
    }

    private static function noteWritten(string $tenantId): void
    {
        $key = $tenantId . ':' . now()->format('Y-m');
        self::$monthCount[$key] = (self::$monthCount[$key] ?? 0) + 1;
    }

    /** Remaining free emails, for the statement and the pre-send estimate. */
    public static function freeRemaining(string $tenantId): int
    {
        return max(0, self::freeAllowance($tenantId) - self::usedThisMonth($tenantId));
    }

    /** Postmark broadcast stream ID, or null when not yet configured. */
    public static function broadcastStream(): ?string
    {
        $s = trim((string) (PlatformSettings::current()->email_broadcast_stream ?? ''));
        return $s !== '' ? $s : null;
    }
}
