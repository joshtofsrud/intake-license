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
            ['group'=>'Transactional','key'=>'sale_receipt','label'=>'Sale receipt','desc'=>'Itemized POS receipt with totals','fires'=>'A register sale is paid','channels'=>['email'],'template'=>'sale_receipt','editor'=>'receipt','vars'=>['first_name','shop_name','sale_number','date','total'],'def_subject'=>'Receipt from {{shop_name}} — #{{sale_number}}','def_body'=>'Thanks for your purchase, {{first_name}}. Your receipt for {{date}} is below.'],
            ['group'=>'Transactional','key'=>'appointment_receipt','label'=>'Work-order receipt','desc'=>'“Your work is complete” plus what it cost','fires'=>'Appointment hits Completed','channels'=>['email'],'template'=>'appointment_receipt','editor'=>'receipt','vars'=>['first_name','shop_name','ra_number','date','total'],'def_subject'=>'Your {{shop_name}} work is complete — #{{ra_number}}','def_body'=>'Hi {{first_name}} — we finished the work on your service request. Here is what we did and what it cost.'],
            ['group'=>'Lifecycle','key'=>'booking_confirmation','label'=>'Booking confirmation','desc'=>'Sent right after a customer books','fires'=>'Customer finishes booking','channels'=>['email','sms'],'template'=>'booking_confirmation','editor'=>'body','vars'=>['first_name','shop_name','appointment_date','total'],'def_subject'=>'Your booking is confirmed — {{shop_name}}','def_body'=>'Hi {{first_name}}, you are booked with {{shop_name}} on {{appointment_date}}. We will send a reminder the day before. Reply to this email if anything changes.'],
            ['group'=>'Lifecycle','key'=>'delivery_scheduled','label'=>'Delivery scheduled','desc'=>'Tells the customer your pickup or dropoff window','fires'=>'You schedule a pickup or dropoff','channels'=>['email','sms'],'template'=>'delivery_scheduled','editor'=>'body','vars'=>['first_name','shop_name','date'],'def_subject'=>'{{shop_name}}: your pickup or dropoff is scheduled','def_body'=>'Hi {{first_name}}, your {{shop_name}} pickup or dropoff is scheduled for {{date}}. Reply to this email if you need to change anything.'],
            ['group'=>'Lifecycle','key'=>'appointment_reminder','label'=>'Appointment reminder','desc'=>'24 hours before the appointment','fires'=>'Daily — 24h before','channels'=>['email','sms'],'template'=>'appointment_reminder','editor'=>'body','vars'=>['first_name','shop_name','date'],'def_subject'=>'Reminder: your {{shop_name}} appointment is tomorrow','def_body'=>'Hi {{first_name}}, a reminder that your appointment with {{shop_name}} is tomorrow, {{date}}.'],
            ['group'=>'Lifecycle','key'=>'delivery_reminder','label'=>'Delivery reminder','desc'=>'24 hours before a pickup or dropoff','fires'=>'Daily — 24h before','channels'=>['email','sms'],'template'=>'delivery_reminder','editor'=>'body','vars'=>['first_name','shop_name','date'],'def_subject'=>'Reminder: {{shop_name}} pickup or dropoff tomorrow','def_body'=>'Hi {{first_name}}, a reminder that your {{shop_name}} pickup or dropoff is tomorrow, {{date}}.'],
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

        $templates = \App\Models\Tenant\TenantEmailTemplate::where('tenant_id', $tenant->id)
            ->get()->keyBy('template_type');

        foreach ($catalog as &$m) {
            $m['state'] = [];
            foreach ($m['channels'] as $ch) {
                $m['state'][$ch] = $tenant->notificationEnabled($m['key'] . '_' . $ch);
            }
            $tpl = $templates[$m['template']] ?? null;
            $m['subject']   = $tpl?->subject   ?? '';
            $m['body']      = $tpl?->body_html ?? '';
            $m['is_custom'] = (bool) ($tpl && $tpl->is_enabled);
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
            'trackOpens'    => (bool) ($tenant->settings['email_track_opens'] ?? true),
            'triggerStates' => (array) ($tenant->settings['receipt_appointment_trigger_states'] ?? ['completed']),
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

        // MARKER-PATCH-407 — receipt options (moved from the Email page).
        $settings['email_track_opens'] = (bool) $request->input('email_track_opens');
        $states = array_values(array_intersect(
            (array) $request->input('receipt_appointment_trigger_states', []),
            ['completed', 'shipped', 'closed']
        ));
        if (! in_array('completed', $states, true)) {
            $states[] = 'completed';
        }
        $settings['receipt_appointment_trigger_states'] = array_values(array_unique($states));

        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Communication settings saved.');
    }

    /**
     * Save (or reset) a message template. Reuses TenantEmailTemplate — the same
     * rows the jobs read. is_enabled=true means "use my custom copy"; the
     * built-in default is used when no enabled row exists. Receipts allow an
     * empty greeting (the layout stands on its own); body messages require a body.
     */
    public function saveTemplate(Request $request, string $type)
    {
        $tenant = tenant();
        $editors = collect($this->catalog())->pluck('editor', 'template'); // [template_type => editor]

        if (! $editors->has($type)) {
            return back()->with('error', 'Unknown message.');
        }

        if ($request->input('op') === 'reset') {
            \App\Models\Tenant\TenantEmailTemplate::where('tenant_id', $tenant->id)
                ->where('template_type', $type)
                ->delete();
            return back()->with('success', 'Reset to the built-in default.');
        }

        $subject = trim((string) $request->input('subject', ''));
        $body    = (string) $request->input('body', '');

        if ($subject === '') {
            return back()->with('error', 'Subject is required.')->withInput();
        }
        if ($editors->get($type) === 'body' && trim($body) === '') {
            return back()->with('error', 'The email body is required.')->withInput();
        }

        \App\Models\Tenant\TenantEmailTemplate::updateOrCreate(
            ['tenant_id' => $tenant->id, 'template_type' => $type],
            ['subject' => $subject, 'body_html' => $body, 'is_enabled' => true]
        );

        return back()->with('success', 'Message updated.');
    }
}
