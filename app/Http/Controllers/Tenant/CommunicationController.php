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
            // MARKER-GC-EMAILS -- only listed for shops that actually have gift
            // cards; the catalog drives the toggles, the editor and the test
            // send, so a shop without them never sees dead switches.
            ['group'=>'Transactional','key'=>'gift_card_delivery','label'=>'Gift card delivery','desc'=>'The card itself, sent to whoever it is for','fires'=>'An e-gift is paid for, or its delivery date arrives','channels'=>['email'],'template'=>'gift_card_delivery','editor'=>'body','vars'=>['recipient_name','shop_name','card_amount','card_code','gift_message','gift_policy','balance_url'],'def_subject'=>'You\'ve received a {{shop_name}} gift card','def_body'=>'{{recipient_name}}, you\'ve been sent a gift card for {{shop_name}}.'],
            ['group'=>'Transactional','key'=>'gift_card_purchase_receipt','label'=>'Gift card purchase receipt','desc'=>'Confirmation to whoever bought the card','fires'=>'A gift card is bought on your website','channels'=>['email'],'template'=>'gift_card_purchase_receipt','editor'=>'body','vars'=>['first_name','shop_name','card_amount','card_type','card_delivery','card_next_step'],'def_subject'=>'Your {{shop_name}} gift card purchase','def_body'=>'Thanks — your gift card purchase went through.'],
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

        // MARKER-GC-EMAILS
        if (! $tenant->gift_cards_visible) {
            $catalog = array_values(array_filter(
                $catalog,
                fn ($m) => ! str_starts_with($m['key'], 'gift_card_')
            ));
        }

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
            'testEmail'     => optional(auth()->user())->email ?? '',
            'emailLogoChoice'    => $tenant->settings['email_logo_choice'] ?? 'light',
            'emailLogoCustomUrl' => $tenant->settings['email_logo_custom_url'] ?? '',
            'logoLight'          => $tenant->logo_light_url ?? '',
            'logoMain'           => $tenant->logo_url ?? '',
            // MARKER-PATCH-412 — tenant tz for converting UTC instants in the Activity feed.
            'tz'                 => $tenant->timezone ?? config('app.timezone', 'UTC'),
        ]);
    }

    public function updateToggles(Request $request)
    {
        $tenant   = tenant();
        $smsReady = $this->smsReady($tenant);

        $settings = $tenant->settings ?? [];
        foreach ($this->catalog() as $m) {
            // MARKER-GC-EMAILS -- skip gift messages the shop cannot send.
            if (str_starts_with($m['key'], 'gift_card_') && ! $tenant->gift_cards_visible) {
                continue;
            }
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

        // MARKER-PATCH-411 — email header logo choice.
        $choice = (string) $request->input('email_logo_choice', 'light');
        $settings['email_logo_choice'] = in_array($choice, ['light', 'main', 'custom', 'none'], true) ? $choice : 'light';
        $settings['email_logo_custom_url'] = trim((string) $request->input('email_logo_custom_url', ''));

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

    /**
     * MARKER-PATCH-409 — send a test of any message to a chosen address.
     * Reuses each message's real send path so the test matches what a customer
     * would receive. Receipts use the tenant's most recent real record; body
     * messages render the saved template (or built-in default) with sample data.
     * Always sends (ignores the on/off toggle) so it works as a pre-launch preview.
     */
    public function sendTest(Request $request, string $type)
    {
        $tenant  = tenant();
        $editors = collect($this->catalog())->pluck('editor', 'template');
        if (! $editors->has($type)) {
            return response()->json(['message' => 'Unknown message.'], 422);
        }

        $email = trim((string) $request->input('test_email', ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Enter a valid email address.'], 422);
        }

        try {
            if ($type === 'sale_receipt') {
                $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                    ->latest()->first();
                if (! $sale) {
                    return response()->json(['message' => 'No sale yet to base a test receipt on — ring one up first.'], 422);
                }
                \App\Jobs\SendSaleReceiptJob::dispatchSync($sale->id, $email, 'manual_resend');

            } elseif ($type === 'appointment_receipt') {
                $appt = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
                    ->latest()->first();
                if (! $appt) {
                    return response()->json(['message' => 'No appointment yet to base a test receipt on.'], 422);
                }
                \App\Jobs\SendAppointmentReceiptJob::dispatchSync($appt->id, $email, 'manual_resend');

            } else {
                // Body-type messages render through EmailService::send(). Delivery
                // splits into pickup/dropoff at the template level; test the pickup
                // variant as representative.
                $renderKey = [
                    'booking_confirmation' => 'booking_confirmation',
                    'appointment_reminder' => 'appointment_reminder',
                    'delivery_scheduled'   => 'delivery_pickup_scheduled',
                    'delivery_reminder'    => 'delivery_pickup_reminder',
                ][$type] ?? $type;

                \App\Services\EmailService::forTenant($tenant)->send($renderKey, $email, $this->sampleVars($tenant));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('comm_send_test_failed', [
                'tenant_id' => $tenant->id, 'type' => $type, 'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Could not send the test — check your email settings.'], 500);
        }

        return response()->json(['message' => 'Test sent to ' . $email . '.']);
    }

    /** Representative sample variables covering the placeholders across templates. */
    private function sampleVars($tenant): array
    {
        $accent = $tenant->accent_color ?? '#BEF264';
        return [
            'first_name'       => 'Alex',
            'last_name'        => 'Rivera',
            'shop_name'        => $tenant->name,
            'ra_number'        => 'ITO-TEST-0001',
            'sale_number'      => 'S-TEST-0001',
            // MARKER-GC-EMAILS
            'recipient_name'   => 'Sam',
            'card_amount'      => '$50.00',
            'card_code'        => 'GC-TEST-0000-0000',
            'gift_message'     => 'Happy birthday — go get something good.',
            'gift_policy'      => \App\Services\Tenant\GiftCardService::config($tenant)['policy_line'],
            'balance_url'      => rtrim((string) $tenant->publicUrl(), '/') . '/gift-cards/balance',
            'card_type'        => 'E-gift card',
            'card_delivery'    => 'Sent to sam@example.com',
            'card_next_step'   => 'The code is in the recipient\'s email.',
            'date'             => now()->format('M j, Y'),
            'date_short'       => now()->addDay()->format('M j'),
            'date_human'       => now()->addDay()->format('l, F j'),
            'time_start'       => '2:00 PM',
            'time_end'         => '2:30 PM',
            'window'           => '2:00 – 2:30 PM',
            'appointment_date' => now()->addDay()->format('l, F j'),
            'appointment_time' => '2:00 PM',
            'when_human'       => now()->addDay()->format('l, F j') . ' at 2:00 PM',
            'when_sms'         => now()->addDay()->format('M j') . ' at 2:00 PM',
            'total'            => '$158.00',
            'accent'           => $accent,
            'accent_text'      => \App\Support\ColorHelper::accentTextColor($accent),
        ];
    }
}
