<?php
// MARKER-PATCH-225

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantStaffAlert;
use App\Models\Tenant\TenantStaffAlertPref;
use App\Models\Tenant\TenantUser;
use App\Services\FeatureAccessService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StaffAlertService — the ONE writer for staff alerts. Every feature that
 * wants to notify staff calls emit(); nothing writes tenant_staff_alerts
 * directly.
 *
 * Gating: the in-app channel needs the staff_alerts addon. CRITICAL events
 * bypass that gate — a shop must never miss a failed payment or an overdue
 * asset because they didn't buy an add-on. The SMS channel always
 * additionally needs sms_notifications (real per-message cost).
 */
class StaffAlertService
{
    /** Events that reach staff even without the staff_alerts addon. */
    private const CRITICAL = ['payment.failed', 'rental.overdue', 'rental.damage_flagged'];

    /** Default channels per event for users who haven't set prefs. */
    private const DEFAULTS = [
        'booking.created'       => ['in_app' => true,  'sms' => false],
        'rental.overdue'        => ['in_app' => true,  'sms' => false],
        'rental.damage_flagged' => ['in_app' => true,  'sms' => false],
        'payment.failed'        => ['in_app' => true,  'sms' => false],
        'offer.accepted'        => ['in_app' => true,  'sms' => false],
        'inbox.needs_reply'     => ['in_app' => true,  'sms' => false],
    ];

    public function __construct(
        protected FeatureAccessService $features,
    ) {}

    /**
     * Emit an alert to the tenant's staff. Fans out per-user after the
     * current DB transaction commits, so an alert can never reference a row
     * that rolled back. Targets active staff users; admins/owners always,
     * others by role later (v1: all active users).
     *
     * @param array{title:string, body?:string, link?:string, meta?:array, only_user_id?:string} $payload
     */
    public function emit(Tenant $tenant, string $event, array $payload): void
    {
        $isCritical = in_array($event, self::CRITICAL, true);

        // In-app gate: non-critical events require the addon.
        if (!$isCritical && !$tenant->staff_alerts_enabled) {
            return;
        }

        $title = $payload['title'] ?? ucfirst(str_replace(['.', '_'], ' ', $event));
        $body  = $payload['body'] ?? null;
        $link  = $payload['link'] ?? null;
        $meta  = $payload['meta'] ?? null;
        $onlyUser = $payload['only_user_id'] ?? null;

        DB::afterCommit(function () use ($tenant, $event, $title, $body, $link, $meta, $isCritical, $onlyUser) {
            try {
                $users = TenantUser::where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->when($onlyUser, fn ($q) => $q->where('id', $onlyUser))
                    ->get();

                if ($users->isEmpty()) {
                    return;
                }

                $prefs = TenantStaffAlertPref::where('tenant_id', $tenant->id)
                    ->where('event', $event)
                    ->whereIn('user_id', $users->pluck('id'))
                    ->get()
                    ->keyBy('user_id');

                $smsAllowed = $this->features->hasAddon($tenant, 'sms_notifications');
                $default = self::DEFAULTS[$event] ?? ['in_app' => true, 'sms' => false];

                foreach ($users as $user) {
                    $pref = $prefs->get($user->id);
                    $wantInApp = $pref ? $pref->in_app : $default['in_app'];
                    $wantSms   = $pref ? $pref->sms : $default['sms'];

                    if ($wantInApp) {
                        TenantStaffAlert::create([
                            'tenant_id'   => $tenant->id,
                            'user_id'     => $user->id,
                            'event'       => $event,
                            'title'       => $title,
                            'body'        => $body,
                            'link'        => $link,
                            'meta'        => $meta,
                            'is_critical' => $isCritical,
                        ]);
                    }

                    if ($wantSms && $smsAllowed && $user->phone) {
                        SmsService::send($tenant, $user->phone, trim($title . ($body ? " — {$body}" : '')));
                    }
                }
            } catch (\Throwable $e) {
                // Alerts are best-effort: never let a notification failure
                // break the operation that triggered it.
                Log::error('staff_alert.emit_failed', [
                    'tenant_id' => $tenant->id, 'event' => $event, 'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
