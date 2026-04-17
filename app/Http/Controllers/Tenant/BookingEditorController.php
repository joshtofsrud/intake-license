<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookingEditorController extends Controller
{
    private const DEFAULTS = [
        'booking_theme'          => 'light',
        'booking_accent'         => '',
        'booking_bg_tint'        => '#FFFFFF',
        'booking_bg_opacity'     => '100',
        'booking_progress_bg'    => '',
        'booking_progress_text'  => '#000000',
        'booking_body_text'      => '',
        'booking_step1_label'    => 'Services',
        'booking_step2_label'    => 'Schedule',
        'booking_step3_label'    => 'Details',
        'booking_step4_label'    => 'Review',
        'booking_step1_heading'  => 'What do you need serviced?',
        'booking_step2_heading'  => 'Pick a drop-off date',
        'booking_step3_heading'  => 'Your details',
        'booking_step4_heading'  => 'Review your order',
        'booking_step1_sub'      => 'Select one or more services.',
        'booking_step2_sub'      => 'Choose a date and tell us how you\'re dropping off.',
        'booking_step3_sub'      => 'Who you are and anything we need to know.',
        'booking_step4_sub'      => 'Confirm everything looks good.',
    ];

    public function index()
    {
        $tenant = tenant();
        $settings = $tenant->settings ?? [];

        // Merge defaults with saved settings
        $booking = [];
        foreach (self::DEFAULTS as $key => $default) {
            $booking[$key] = $settings[$key] ?? $default;
        }

        return view('tenant.booking-editor.index', compact('booking'));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('save_booking')) {
            $settings = $tenant->settings ?? [];

            foreach (self::DEFAULTS as $key => $default) {
                if ($request->has($key)) {
                    $settings[$key] = $request->input($key);
                }
            }

            $tenant->update(['settings' => $settings]);

            if ($request->expectsJson()) {
                return response()->json(['ok' => true]);
            }

            return back()->with('success', 'Booking form settings saved.');
        }

        return back();
    }
}
