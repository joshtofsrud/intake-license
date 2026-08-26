<?php
// MARKER-EMAIL-CONSENT

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantConsentAttestation;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantUser;

/**
 * Email marketing consent, in one place.
 *
 * Semantics: unsubscribe stops MARKETING only — receipts and reminders
 * keep sending. Bounce/complaint suppression (tenant_email_suppressions)
 * remains the hard block for all mail and is checked at send time, not here.
 */
class ConsentService
{
    public function recordOptIn(TenantCustomer $customer, string $source): void
    {
        $customer->update([
            'email_marketing_consent_at'     => now(),
            'email_marketing_consent_source' => $source,
            'email_marketing_opt_out_at'     => null,
        ]);
    }

    public function optOut(TenantCustomer $customer): void
    {
        if ($customer->email_marketing_opt_out_at === null) {
            $customer->update(['email_marketing_opt_out_at' => now()]);
        }
    }

    /**
     * A shop's owner/manager attests they have permission for a set of
     * unconfirmed contacts. Marks them consented (source 'attestation')
     * and freezes the exact wording, count, who, when and IP.
     * Never touches customers who already opted out. Returns customers marked.
     */
    public function attest(
        Tenant $tenant,
        ?TenantUser $confirmedBy,
        string $wording,
        ?string $ip,
        array $context = [],
        ?array $customerIds = null
    ): int {
        $query = TenantCustomer::where('tenant_id', $tenant->id)
            ->whereNotNull('email')->where('email', '!=', '')
            ->whereNull('email_marketing_consent_at')
            ->whereNull('email_marketing_opt_out_at');

        if ($customerIds !== null) {
            $query->whereIn('id', $customerIds);
        }

        $marked = 0;
        $query->select('id')->chunkById(500, function ($chunk) use (&$marked) {
            $marked += TenantCustomer::whereIn('id', $chunk->pluck('id'))->update([
                'email_marketing_consent_at'     => now(),
                'email_marketing_consent_source' => 'attestation',
            ]);
        });

        TenantConsentAttestation::create([
            'tenant_id'            => $tenant->id,
            'contact_count'        => $marked,
            'wording'              => $wording,
            'confirmed_by_user_id' => $confirmedBy?->id,
            'confirmed_by_name'    => (string) ($confirmedBy?->name ?? 'Unknown'),
            'confirmed_by_role'    => $confirmedBy?->role,
            'ip'                   => $ip,
            'context'              => $context,
        ]);

        return $marked;
    }

    // ------------------------------------------------------------------
    // Unsubscribe links — stateless APP_KEY HMAC over the customer id.
    // No DB token, no expiry: an unsubscribe link must work forever,
    // and (learned the hard way) never live in the cache.
    // ------------------------------------------------------------------

    public static function unsubscribeSignature(string $customerId): string
    {
        return hash_hmac('sha256', 'email-unsub:' . $customerId, (string) config('app.key'));
    }

    public static function signatureValid(string $customerId, string $sig): bool
    {
        return hash_equals(self::unsubscribeSignature($customerId), $sig);
    }

    /** Absolute unsubscribe URL on the tenant's public site. */
    public static function unsubscribeUrl(Tenant $tenant, TenantCustomer $customer): string
    {
        return rtrim((string) $tenant->publicUrl(), '/')
            . '/email/unsubscribe/' . $customer->id
            . '/' . self::unsubscribeSignature($customer->id);
    }
}
