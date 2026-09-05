<?php
// MARKER-PATCH-225

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantStaffAlert;
use App\Models\Tenant\TenantStaffAlertBroadcast;
use App\Models\Tenant\TenantStaffAlertPref;
use App\Models\Tenant\TenantUser;
use App\Services\EmailService;
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
    // MARKER-PATCH-247 — link completions and external refunds are money
    // events staff must never miss; same class as payment.failed.
    private const CRITICAL = ['payment.failed', 'rental.overdue', 'rental.damage_flagged', 'payment.link_completed', 'payment.refund_external'];

    /** Default channels per event for users who haven't set prefs. */
    private const DEFAULTS = [
        'booking.created'       => ['in_app' => true,  'sms' => false],
        'rental.overdue'        => ['in_app' => true,  'sms' => false],
        'rental.damage_flagged' => ['in_app' => true,  'sms' => false],
        'payment.failed'        => ['in_app' => true,  'sms' => false],
        'offer.accepted'        => ['in_app' => true,  'sms' => false],
        'inbox.needs_reply'     => ['in_app' => true,  'sms' => false],
        // MARKER-PATCH-247 — coverage sweep.
        'payment.link_completed' => ['in_app' => true,  'sms' => false],
        'payment.link_expired'   => ['in_app' => true,  'sms' => false],
        'payment.refund_external'=> ['in_app' => true,  'sms' => false],
        'rental.reserved_online' => ['in_app' => true,  'sms' => false],
        'lease.created'          => ['in_app' => true,  'sms' => false],
        'announcement'           => ['in_app' => true,  'sms' => false],
        // MARKER-DELIVERY-ALERTS
        'delivery.window_chosen' => ['in_app' => true,  'sms' => false],
        'delivery.no_reply'      => ['in_app' => true,  'sms' => false],
        'delivery.call_requested'=> ['in_app' => true,  'sms' => false], // MARKER-DELIVERY-CALL
        // MARKER-TOFF-ALERTS
        'timeoff.requested'      => ['in_app' => true,  'sms' => false],
        'timeoff.decided'        => ['in_app' => true,  'sms' => false],
        'timeoff.withdrawn'      => ['in_app' => true,  'sms' => false],
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

    /**
     * MARKER-PATCH-279 — Shop-wide announcement (Layer B). Persists the
     * broadcast, then fans it out to the in-app inbox via emit() so per-user
     * prefs and the addon gate are reused. Email + banner are handled by the
     * broadcast UI layer (patches 280/281).
     *
     * @param array{title:string, body?:string, link?:string, priority?:string, audience?:array, show_banner?:bool, send_email?:bool, expires_at?:mixed} $data
     */
    public function broadcast(Tenant $tenant, array $data, ?string $createdBy = null): ?TenantStaffAlertBroadcast
    {
        // Announcements are an addon feature, not a critical safety event.
        if (!$tenant->staff_alerts_enabled) {
            return null;
        }

        $bc = TenantStaffAlertBroadcast::create([
            'tenant_id'   => $tenant->id,
            'created_by'  => $createdBy,
            'title'       => $data['title'],
            'body'        => $data['body'] ?? null,
            'priority'    => ($data['priority'] ?? 'low') === 'high' ? 'high' : 'low',
            'audience'    => $data['audience'] ?? null,
            'show_banner' => (bool) ($data['show_banner'] ?? true),
            'send_email'  => (bool) ($data['send_email'] ?? false),
            'expires_at'  => $data['expires_at'] ?? null,
            'is_active'   => true,
        ]);

        $this->emit($tenant, 'announcement', [
            'title' => $data['title'],
            'body'  => $data['body'] ?? null,
            'link'  => $data['link'] ?? null,
            'meta'  => ['broadcast_id' => $bc->id, 'priority' => $bc->priority],
        ]);

        if ($bc->send_email) {
            $this->emailBroadcast($tenant, $bc);
        }

        return $bc;
    }

    /**
     * MARKER-PATCH-282 — email an announcement to every active staff member.
     * Best-effort: reuses EmailService (suppression gate, tenant from-address,
     * Postmark metadata) and its branded shell; failures are logged only.
     */
    private function emailBroadcast(Tenant $tenant, TenantStaffAlertBroadcast $bc): void
    {
        try {
            $recipients = TenantUser::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique();

            if ($recipients->isEmpty()) {
                return;
            }

            $mailer  = new EmailService($tenant);
            $subject = '📣 ' . $bc->title;

            $inner = '<h1 style="font-size:18px;margin:0 0 12px;color:#111">' . e($bc->title) . '</h1>';
            if ($bc->body) {
                $inner .= '<p style="font-size:14px;line-height:1.6;color:#333;white-space:pre-line;margin:0">'
                        . e($bc->body) . '</p>';
            }
            $inner .= '<p style="font-size:12px;color:#999;margin-top:20px">Staff announcement from '
                    . e($tenant->name) . '</p>';

            $html = $mailer->renderHtml($inner);

            foreach ($recipients as $email) {
                $mailer->sendRendered('announcement', $email, $subject, $html);
            }
        } catch (\Throwable $e) {
            Log::warning('Broadcast email failed: ' . $e->getMessage());
        }
    }
}
