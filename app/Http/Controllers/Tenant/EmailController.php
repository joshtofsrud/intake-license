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

        return view('tenant.emails.index', compact('types'));
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
}
