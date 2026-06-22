<?php
// MARKER-PATCH-404

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantNotificationLog;
use Illuminate\Http\Request;

/**
 * Communication Center — the single source of truth for "what does my shop
 * send." Toggles write to the same notify_* keys in tenant.settings that every
 * sender already reads via Tenant::notificationEnabled(), so a switch here
 * takes effect with no changes to any job or command.
 *
 * Receipts (sale + appointment) are email-only; lifecycle messages do email +
 * SMS. SMS keys are never persisted "on" while Twilio is unprovisioned, so a
 * switch that cannot deliver is never left in an on state.
 */
class CommunicationController extends Controller
{
    /**
     * Message catalog. `channels` are the notify_<key>_<channel> keys that
     * exist for each message. `editor` picks the future drawer mode (patch-405).
     */
    private function catalog(): array
    {
        return [
            ['group'=>'Transactional','key'=>'sale_receipt','label'=>'Sale receipt','desc'=>'Itemized POS receipt with totals','fires'=>'A register sale is paid','channels'=>['email'],'template'=>'sale_receipt','editor'=>'receipt'],
            ['group'=>'Transactional','key'=>'appointment_receipt','label'=>'Work-order receipt','desc'=>'“Your work is complete” plus what it cost','fires'=>'Appointment hits Completed','channels'=>['email'],'template'=>'appointment_receipt','editor'=>'receipt'],
            ['group'=>'Lifecycle','key'=>'booking_confirmation','label'=>'Booking confirmation','desc'=>'Sent right after a customer books','fires'=>'Customer finishes booking','channels'=>['email','sms'],'template'=>'booking_confirmation','editor'=>'body'],
            ['group'=>'Lifecycle','key'=>'delivery_scheduled','label'=>'Delivery scheduled','desc'=>'Tells the customer your pickup or dropoff window','fires'=>'You schedule a pickup or dropoff','channels'=>['email','sms'],'template'=>'delivery_scheduled','editor'=>'body'],
            ['group'=>'Lifecycle','key'=>'appointment_reminder','label'=>'Appointment reminder','desc'=>'24 hours before the appointment','fires'=>'Daily — 24h before','channels'=>['email','sms'],'template'=>'appointment_reminder','editor'=>'body'],
            ['group'=>'Lifecycle','key'=>'delivery_reminder','label'=>'Delivery reminder','desc'=>'24 hours before a pickup or dropoff','fires'=>'Daily — 24h before','channels'=>['email','sms'],'template'=>'delivery_reminder','editor'=>'body'],
        ];
    }

    private function smsReady($tenant): bool
    {
        return (bool) ($tenant->sms_enabled && $tenant->twilio_account_sid && $tenant->twilio_auth_token);
    }

    public function index()
    {
        $tenant  = tenant();
        $catalog = $this->catalog();

        foreach ($catalog as &$m) {
            $m['state'] = [];
            foreach ($m['channels'] as $ch) {
                $m['state'][$ch] = $tenant->notificationEnabled($m['key'] . '_' . $ch);
            }
        }
        unset($m);

        $logs = TenantNotificationLog::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('tenant.communication.index', [
            'pageTitle' => 'Communication',
            'catalog'   => $catalog,
            'smsReady'  => $this->smsReady($tenant),
            'logs'      => $logs,
            'fromName'  => $tenant->emailFromName(),
            'fromEmail' => $tenant->emailFromAddress(),
            'replyTo'   => $tenant->email_reply_to,
            'smsNumber' => $tenant->sms_from_number,
        ]);
    }

    public function updateToggles(Request $request)
    {
        $tenant   = tenant();
        $smsReady = $this->smsReady($tenant);

        $settings = $tenant->settings ?? [];
        foreach ($this->catalog() as $m) {
            foreach ($m['channels'] as $ch) {
                // Never persist SMS on when texting cannot deliver.
                if ($ch === 'sms' && ! $smsReady) {
                    continue;
                }
                $field = 'notify_' . $m['key'] . '_' . $ch;
                $settings[$field] = (bool) $request->input($field);
            }
        }
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Communication settings saved.');
    }
}
