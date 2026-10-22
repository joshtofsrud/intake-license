<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Self check-in on a studio tablet, opened from a private link. Members tap
 * their class, then their name, and sign the waiver there if they need to.
 * No prices, notes or flags are shown; anyone who owes money or has a
 * membership payment due is sent to the desk. MARKER-CLASSES-P10
 */
class ClassKiosk
{
    /** Classes open for self check-in: starting within the hour, or started up to 15 minutes ago. */
    public const OPENS_MINUTES  = 60;
    public const CLOSES_MINUTES = 15;

    public static function token(Tenant $tenant, bool $fresh = false): string
    {
        $settings = (array) ($tenant->settings ?? []);
        if ($fresh || empty($settings['class_kiosk_token'])) {
            $settings['class_kiosk_token'] = Str::random(40);
            $tenant->forceFill(['settings' => $settings])->save();
        }
        return $settings['class_kiosk_token'];
    }

    public static function valid(Tenant $tenant, string $token): bool
    {
        $have = (string) (((array) ($tenant->settings ?? []))['class_kiosk_token'] ?? '');
        return $have !== '' && hash_equals($have, $token);
    }

    public static function url(Tenant $tenant): string
    {
        return $tenant->urlTo('/classes/self-check-in/' . self::token($tenant));
    }

    public static function sessions(Tenant $tenant): Collection
    {
        return TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('starts_at', [now()->subMinutes(self::CLOSES_MINUTES), now()->addMinutes(self::OPENS_MINUTES)])
            ->with('template')->orderBy('starts_at')->get();
    }

    public static function open(Tenant $tenant, string $sessionId): ?TenantClassSession
    {
        return self::sessions($tenant)->firstWhere('id', $sessionId);
    }

    /** Names as members see them: first name and last initial, no flags. */
    public static function people(Tenant $tenant, TenantClassSession $s): Collection
    {
        return ClassCheckin::rows($tenant, $s)->map(function ($x) {
            $c = $x['reg']->customer;
            $label = trim(($c?->first_name ?: '') . ' ' . ($c?->last_name ? mb_substr($c->last_name, 0, 1) . '.' : ''));
            return ['id' => $x['reg']->id, 'label' => $label !== '' ? $label : 'Guest', 'in' => $x['reg']->status === 'checked_in'];
        })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Check one person in from the tablet.
     * @return array{state:string, name:string, waiver?:\App\Models\Tenant\TenantClassWaiver}
     *   state: done | already | desk | waiver | gone
     */
    public static function checkIn(Tenant $tenant, TenantClassSession $s, string $registrationId, ?string $waiverName, bool $agreed, ?string $ip): array
    {
        $row = ClassCheckin::rows($tenant, $s)->first(fn ($x) => $x['reg']->id === $registrationId);
        if (! $row) {
            return ['state' => 'gone', 'name' => ''];
        }
        $name = $row['reg']->customer?->first_name ?: 'there';
        if ($row['reg']->status === 'checked_in') {
            return ['state' => 'already', 'name' => $name];
        }
        if ($row['desk']) {
            return ['state' => 'desk', 'name' => $name];
        }
        if ($row['needs_waiver']) {
            if (! $agreed || trim((string) $waiverName) === '') {
                return ['state' => 'waiver', 'name' => $name, 'waiver' => ClassWaivers::current($tenant)];
            }
            ClassWaivers::sign($tenant, $row['reg']->customer_id, (string) $waiverName, 'kiosk', $ip);
        }
        app(ClassRegistrationService::class)->checkIn($row['reg']->id, $tenant->id);
        return ['state' => 'done', 'name' => $name];
    }
}
