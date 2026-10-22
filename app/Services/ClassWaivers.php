<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassWaiver;
use App\Models\Tenant\TenantClassWaiverSignature;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Support\Facades\URL;

/**
 * The studio's class waiver. Off until the studio writes one and turns it on
 * (Classes › Settings). Changing the text makes a new version; everyone signs
 * the new one at their next booking. Nobody loses a booking over a missing
 * signature: staff see it flagged at check-in. MARKER-CLASSES-P9
 */
class ClassWaivers
{
    /** The waiver customers must sign now, or null when the studio doesn't use one. */
    public static function current(Tenant $tenant): ?TenantClassWaiver
    {
        if (! ClassRules::for($tenant)->get('waiver_on')) {
            return null;
        }
        return self::latest($tenant);
    }

    public static function latest(Tenant $tenant): ?TenantClassWaiver
    {
        return TenantClassWaiver::where('tenant_id', $tenant->id)->orderByDesc('version')->first();
    }

    public static function needs(Tenant $tenant, ?string $customerId): bool
    {
        $w = self::current($tenant);
        if (! $w || ! $customerId) {
            return (bool) $w;
        }
        return ! TenantClassWaiverSignature::where('tenant_id', $tenant->id)->where('customer_id', $customerId)
            ->where('version', $w->version)->exists();
    }

    /** Customer ids (of those given) who still need to sign the current version. */
    public static function needing(Tenant $tenant, array $customerIds): array
    {
        $w = self::current($tenant);
        if (! $w || ! $customerIds) {
            return [];
        }
        $signed = TenantClassWaiverSignature::where('tenant_id', $tenant->id)->where('version', $w->version)
            ->whereIn('customer_id', $customerIds)->pluck('customer_id')->all();
        return array_values(array_diff($customerIds, $signed));
    }

    public static function sign(Tenant $tenant, string $customerId, string $name, string $method, ?string $ip = null, ?string $userId = null): ?TenantClassWaiverSignature
    {
        $w = self::current($tenant);
        if (! $w || ! self::needs($tenant, $customerId)) {
            return null;
        }
        return TenantClassWaiverSignature::create([
            'tenant_id'         => $tenant->id,
            'customer_id'       => $customerId,
            'waiver_id'         => $w->id,
            'version'           => $w->version,
            'signer_name'       => mb_substr(trim($name), 0, 160),
            'method'            => $method,
            'ip'                => $ip,
            'signed_by_user_id' => $userId,
            'signed_at'         => now(),
        ]);
    }

    /** Save the text; a change makes a new version. Returns the version now in force. */
    public static function saveText(Tenant $tenant, string $body, ?string $userId = null): ?TenantClassWaiver
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $last = self::latest($tenant);
        if ($body === '' || ($last && trim($last->body) === $body)) {
            return $last;
        }
        return TenantClassWaiver::create([
            'tenant_id'          => $tenant->id,
            'version'            => ($last?->version ?? 0) + 1,
            'body'               => $body,
            'created_by_user_id' => $userId,
        ]);
    }

    public static function signedCount(Tenant $tenant, TenantClassWaiver $w): int
    {
        return TenantClassWaiverSignature::where('tenant_id', $tenant->id)->where('version', $w->version)
            ->distinct('customer_id')->count('customer_id');
    }

    /** A link the customer can open for 14 days to read and sign. */
    public static function signUrl(Tenant $tenant, TenantCustomer $c): string
    {
        $path = URL::temporarySignedRoute('tenant.customer.classes.waiver', now()->addDays(14), ['c' => $c->id], false);
        return $tenant->urlTo($path);
    }

    /** Email the customer a link to sign. False when emails are off or there's no address. */
    public static function emailLink(Tenant $tenant, TenantCustomer $c): bool
    {
        if (! filled($c->email) || ! $tenant->notificationEnabled('class_waiver_request_email')) {
            return false;
        }
        (new EmailService($tenant))->send('class_waiver_request', $c->email, [
            'first_name' => e($c->first_name ?: 'there'),
            'shop_name'  => e($tenant->name),
            'waiver_url' => self::signUrl($tenant, $c),
        ]);
        return true;
    }
}
