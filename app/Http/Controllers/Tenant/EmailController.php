<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailController extends Controller
{
    private const TYPES = [
        'booking_confirmation' => [
            'label' => 'Booking confirmation',
            'desc'  => 'Sent to the customer immediately after they complete a booking.',
            'vars'  => ['first_name', 'ra_number', 'appointment_date', 'total', 'status'],
        ],
        'status_update' => [
            'label' => 'Status update',
            'desc'  => 'Sent when you change the status of a work order.',
            'vars'  => ['first_name', 'ra_number', 'status', 'status_note'],
        ],
        'password_reset' => [
            'label' => 'Password reset',
            'desc'  => 'Sent to staff members when they request a password reset.',
            'vars'  => ['name', 'reset_url', 'shop_name', 'accent', 'accent_text'],
        ],
        // MARKER-PATCH-160
        'sale_receipt' => [
            'label' => 'Sale receipt',
            'desc'  => 'Sent automatically when a POS sale is paid. Itemized lines and totals come from the sale itself; the subject + greeting below are customizable.',
            'vars'  => ['first_name', 'sale_number', 'shop_name', 'date', 'total'],
        ],
        'appointment_receipt' => [
            'label' => 'Appointment work-order receipt',
            'desc'  => 'Sent when a service appointment is marked complete (or other states you choose). Work performed + totals come from the appointment; the subject + greeting below are customizable.',
            'vars'  => ['first_name', 'ra_number', 'shop_name', 'date', 'total'],
        ],
    ];

    public function index()
    {
        $tenant    = tenant();
        $templates = TenantEmailTemplate::where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('template_type');

        $types = [];
        foreach (self::TYPES as $key => $meta) {
            $custom = $templates[$key] ?? null;
            $types[$key] = array_merge($meta, [
                'key'       => $key,
                'is_custom' => (bool) $custom,
                'is_active' => $custom ? (bool) $custom->is_enabled : true,
                'subject'   => $custom?->subject   ?? '',
                'body'      => $custom?->body_html ?? '',
            ]);
        }

        // MARKER-PATCH-161 — receipt automation state for the toggle card.
        $settings = $tenant->settings ?? [];
        $receiptSettings = [
            'notify_sale_receipt_email'           => (bool) ($settings['notify_sale_receipt_email']        ?? true),
            'notify_appointment_receipt_email'    => (bool) ($settings['notify_appointment_receipt_email'] ?? true),
            'email_track_opens'                   => (bool) ($settings['email_track_opens']               ?? true),
            'receipt_appointment_trigger_states'  => $settings['receipt_appointment_trigger_states']      ?? ['completed'],
        ];

        return view('tenant.emails.index', compact('types', 'receiptSettings'));
    }

    public function update(Request $request, string $type)
    {
        Log::info('EMAIL_UPDATE_HIT', [
            'type'      => $type,
            'tenant_id' => tenant()?->id,
            'subject'   => $request->input('subject'),
            'body_len'  => strlen((string) $request->input('body')),
            'op'        => $request->input('op'),
            'is_active' => $request->input('is_active'),
        ]);

        if (! array_key_exists($type, self::TYPES)) {
            Log::warning('EMAIL_UPDATE: unknown type', ['type' => $type]);
            return back()->with('error', 'Unknown template type.');
        }

        $op = $request->input('op', 'save');

        if ($op === 'reset') {
            TenantEmailTemplate::where('tenant_id', tenant()->id)
                ->where('template_type', $type)
                ->delete();
            Log::info('EMAIL_UPDATE: reset done');
            return back()->with('success', 'Template reset to default.');
        }

        // Manual validation so we can log failures instead of redirecting silently
        $subject = trim((string) $request->input('subject', ''));
        $body    = (string) $request->input('body', '');

        if ($subject === '' || $body === '') {
            Log::warning('EMAIL_UPDATE: empty subject or body', [
                'subject_empty' => $subject === '',
                'body_empty'    => $body === '',
            ]);
            return back()->with('error', 'Subject and body are required.')->withInput();
        }

        try {
            $result = TenantEmailTemplate::updateOrCreate(
                [
                    'tenant_id'     => tenant()->id,
                    'template_type' => $type,
                ],
                [
                    'subject'    => $subject,
                    'body_html'  => $body,
                    'is_enabled' => (bool) $request->input('is_active', 0),
                ]
            );

            Log::info('EMAIL_UPDATE: saved', [
                'id'                 => $result->id,
                'wasRecentlyCreated' => $result->wasRecentlyCreated,
                'subject'            => $result->subject,
                'body_len'           => strlen((string) $result->body_html),
            ]);
        } catch (\Throwable $e) {
            Log::error('EMAIL_UPDATE: save threw', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return back()->with('error', 'Save failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Template saved.');
    }

    /**
     * MARKER-PATCH-161 — Save tenant-wide receipt automation toggles + trigger states.
     * Writes into the tenants.settings JSON column.
     */
    public function settingsUpdate(Request $request)
    {
        $tenant = tenant();

        $validated = $request->validate([
            'notify_sale_receipt_email'        => 'nullable|boolean',
            'notify_appointment_receipt_email' => 'nullable|boolean',
            'email_track_opens'                => 'nullable|boolean',
            'receipt_appointment_trigger_states'   => 'nullable|array',
            'receipt_appointment_trigger_states.*' => 'string|in:completed,shipped,closed',
        ]);

        $settings = $tenant->settings ?? [];

        // Checkboxes only post when checked, so use input() with default false
        // for the booleans rather than reading $validated which may omit keys.
        $settings['notify_sale_receipt_email']        = (bool) $request->input('notify_sale_receipt_email', false);
        $settings['notify_appointment_receipt_email'] = (bool) $request->input('notify_appointment_receipt_email', false);
        $settings['email_track_opens']                = (bool) $request->input('email_track_opens', false);

        // Trigger states: require at least 'completed' so receipts always fire on the default state.
        $states = $validated['receipt_appointment_trigger_states'] ?? [];
        if (! in_array('completed', $states, true)) {
            $states[] = 'completed';
        }
        $settings['receipt_appointment_trigger_states'] = array_values(array_unique($states));

        $tenant->update(['settings' => $settings]);

        Log::info('EMAIL_SETTINGS_SAVED', [
            'tenant_id' => $tenant->id,
            'settings'  => array_intersect_key($settings, array_flip([
                'notify_sale_receipt_email',
                'notify_appointment_receipt_email',
                'email_track_opens',
                'receipt_appointment_trigger_states',
            ])),
        ]);

        return back()->with('success', 'Receipt automation settings saved.');
    }
}
