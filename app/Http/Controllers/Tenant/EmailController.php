<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailTemplate;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    // Template types and their human labels
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
    ];

    // ----------------------------------------------------------------
    // Index — list all templates
    // ----------------------------------------------------------------
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

        return view('tenant.emails.index', compact('types'));
    }

    // ----------------------------------------------------------------
    // Update — save a template
    // ----------------------------------------------------------------
    public function update(Request $request, string $type)
    {
        if (! array_key_exists($type, self::TYPES)) {
            return back()->with('error', 'Unknown template type.');
        }

        $op = $request->input('op', 'save');

        // Reset to default
        if ($op === 'reset') {
            TenantEmailTemplate::where('tenant_id', tenant()->id)
                ->where('template_type', $type)
                ->delete();
            return back()->with('success', 'Template reset to default.');
        }

        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body'      => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        TenantEmailTemplate::updateOrCreate(
            [
                'tenant_id'     => tenant()->id,
                'template_type' => $type,
            ],
            [
                'subject'    => $validated['subject'],
                'body_html'  => $validated['body'],
                'is_enabled' => (bool) $request->input('is_active', 0),
            ]
        );

        return back()->with('success', 'Template saved.');
    }
}
