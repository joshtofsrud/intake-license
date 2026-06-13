<?php
// MARKER-PATCH-225

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantStaffAlert;
use App\Models\Tenant\TenantStaffAlertPref;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAlertController extends Controller
{
    private const EVENTS = [
        'booking.created'       => 'New booking',
        'rental.overdue'        => 'Rental overdue',
        'rental.damage_flagged' => 'Rental damage / deposit captured',
        'payment.failed'        => 'Payment failed',
        'offer.accepted'        => 'Extension offer accepted',
        'inbox.needs_reply'     => 'Inbox needs a reply',
        // MARKER-PATCH-247
        'payment.link_completed' => 'Payment link completed',
        'payment.link_expired'   => 'Payment link expired unpaid',
        'payment.refund_external'=> 'Refund issued outside Intake',
        'rental.reserved_online' => 'New online rental reservation',
        'lease.created'          => 'New lease',
    ];

    /** MARKER-PATCH-231 — full notifications page (grouped, paginated). */
    public function page(Request $request)
    {
        $userId = auth('tenant')->id();

        $alerts = TenantStaffAlert::where('tenant_id', tenant()->id)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(40);

        return view('tenant.notifications', ['alerts' => $alerts]);
    }

    /** Bell dropdown feed (JSON). */
    public function feed(Request $request)
    {
        $userId = auth('tenant')->id();

        $alerts = TenantStaffAlert::where('tenant_id', tenant()->id)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $unread = TenantStaffAlert::where('tenant_id', tenant()->id)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'unread' => $unread,
            'alerts' => $alerts->map(fn ($a) => [
                'id'          => $a->id,
                'title'       => $a->title,
                'body'        => $a->body,
                'link'        => $a->link,
                'is_critical' => $a->is_critical,
                'read'        => $a->read_at !== null,
                'ago'         => $a->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        TenantStaffAlert::where('tenant_id', tenant()->id)
            ->where('user_id', auth('tenant')->id())
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        TenantStaffAlert::where('tenant_id', tenant()->id)
            ->where('user_id', auth('tenant')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function prefs()
    {
        // MARKER-PATCH-272 prefs gate — config requires the Branded+ addon.
        if (!tenant()->staff_alerts_enabled) {
            return redirect()->route('tenant.notifications')
                ->with('flash', 'Staff alert preferences are available on the Branded plan and above.');
        }
        $userId = auth('tenant')->id();

        $existing = TenantStaffAlertPref::where('tenant_id', tenant()->id)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('event');

        $rows = [];
        foreach (self::EVENTS as $event => $label) {
            $pref = $existing->get($event);
            $rows[] = [
                'event'  => $event,
                'label'  => $label,
                'in_app' => $pref ? $pref->in_app : true,
                'sms'    => $pref ? $pref->sms : false,
            ];
        }

        return view('tenant.settings.alert-prefs', [
            'rows'        => $rows,
            'smsAvailable' => tenant()->sms_enabled && (bool) tenant()->sms_from_number,
        ]);
    }

    public function savePrefs(Request $request)
    {
        // MARKER-PATCH-272 savePrefs gate.
        if (!tenant()->staff_alerts_enabled) {
            abort(403, 'Staff alerts is not available on your plan.');
        }
        $userId = auth('tenant')->id();
        $tenantId = tenant()->id;
        $selected = $request->input('prefs', []);

        DB::transaction(function () use ($selected, $tenantId, $userId) {
            foreach (array_keys(self::EVENTS) as $event) {
                $row = $selected[$event] ?? [];
                TenantStaffAlertPref::updateOrCreate(
                    ['tenant_id' => $tenantId, 'user_id' => $userId, 'event' => $event],
                    ['in_app' => !empty($row['in_app']), 'sms' => !empty($row['sms'])],
                );
            }
        });

        return redirect()->route('tenant.alerts.prefs')->with('flash', 'Notification preferences saved.');
    }
}
