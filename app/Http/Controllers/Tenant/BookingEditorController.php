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
        'booking_show_nav'       => '1', // MARKER-PATCH-589 — site nav
        'booking_show_footer'    => '1', // MARKER-PATCH-589 — site footer
        'booking_show_logo'      => '1', // MARKER-PATCH-589 — page's own logo header
        'booking_hide_cta'       => '0', // MARKER-PATCH-590 — hide pre-footer CTA band on booking page
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

        // MARKER-PATCH-589 — brand kit palette (same source as page builder)
        $saved = tenant()->settings['brand_kit'] ?? null;
        $brandKit = is_array($saved) && count($saved)
            ? array_values(array_map(fn ($c) => [
                'name' => (string) ($c['name'] ?? 'Color'),
                'value' => (string) ($c['value'] ?? '#000000'),
              ], $saved))
            : [
                ['name' => 'Accent',     'value' => tenant()->accent_color ?: '#BEF264'],
                ['name' => 'Text',       'value' => tenant()->text_color   ?: '#111111'],
                ['name' => 'Background', 'value' => tenant()->bg_color     ?: '#FFFFFF'],
              ];

        // MARKER-PATCH-601 — marketing sections (before/after the form)
        $bookingSections = is_array($settings['booking_sections'] ?? null) ? $settings['booking_sections'] : [];

        return view('tenant.booking-editor.index', compact('booking', 'brandKit', 'bookingSections'));
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

            // MARKER-PATCH-601 — marketing sections arrive as a JSON blob (the
            // flat data-bke loop can't carry a nested, ordered, variable list).
            if ($request->has('booking_sections')) {
                $settings['booking_sections'] = $this->sanitizeSections($request->input('booking_sections'));
            }

            $tenant->update(['settings' => $settings]);

            if ($request->expectsJson()) {
                return response()->json(['ok' => true]);
            }

            return back()->with('success', 'Booking form settings saved.');
        }

        return back();
    }

    // MARKER-PATCH-601 — validate/normalize the marketing sections payload.
    // Whitelist types + positions, coerce field types, strip unknown keys, and
    // run custom HTML through the same purifier the block builder uses.
    private function sanitizeSections($raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($list)) {
            return [];
        }

        $types     = ['hero', 'cta', 'feature_grid', 'custom_html'];
        $positions = ['before', 'after'];
        $aligns    = ['left', 'center', 'right'];
        $out = [];

        foreach ($list as $s) {
            if (!is_array($s)) { continue; }
            $type = in_array(($s['type'] ?? ''), $types, true) ? $s['type'] : null;
            if ($type === null) { continue; }

            $row = [
                'id'           => (string) ($s['id'] ?? \Illuminate\Support\Str::uuid()),
                'type'         => $type,
                'position'     => in_array(($s['position'] ?? 'before'), $positions, true) ? $s['position'] : 'before',
                'bg_color'     => $this->hex($s['bg_color'] ?? ''),
                'bg_image_url' => $this->url($s['bg_image_url'] ?? ''),
                'text_color'   => $this->hex($s['text_color'] ?? ''),
                'align'        => in_array(($s['align'] ?? 'center'), $aligns, true) ? $s['align'] : 'center',
                'pad_top'      => $this->clampInt($s['pad_top'] ?? 56, 0, 240),
                'pad_bottom'   => $this->clampInt($s['pad_bottom'] ?? 56, 0, 240),
            ];

            if ($type === 'custom_html') {
                $html = (string) ($s['html'] ?? '');
                // Reuse the block builder's sanitizer if available; else strip tags conservatively.
                if (class_exists(\App\Services\Tenant\BlockRenderer::class)
                    && method_exists(\App\Services\Tenant\BlockRenderer::class, 'sanitizeHtml')) {
                    $row['html'] = \App\Services\Tenant\BlockRenderer::sanitizeHtml($html);
                } else {
                    $row['html'] = strip_tags($html, '<p><br><strong><em><a><ul><ol><li><h2><h3><span><div><img>');
                }
            } elseif ($type === 'feature_grid') {
                $row['headline'] = $this->text($s['headline'] ?? '');
                $row['subtext']  = $this->text($s['subtext'] ?? '');
                $feats = is_array($s['features'] ?? null) ? $s['features'] : [];
                $row['features'] = [];
                foreach (array_slice($feats, 0, 12) as $f) {
                    if (!is_array($f)) { continue; }
                    $row['features'][] = [
                        'icon'  => $this->text($f['icon'] ?? '', 8),
                        'title' => $this->text($f['title'] ?? '', 80),
                        'text'  => $this->text($f['text'] ?? '', 240),
                    ];
                }
            } else { // hero | cta
                $row['eyebrow']    = $this->text($s['eyebrow'] ?? '', 80);
                $row['headline']   = $this->text($s['headline'] ?? '');
                $row['subtext']    = $this->text($s['subtext'] ?? '', 400);
                $row['btn_label']  = $this->text($s['btn_label'] ?? '', 60);
                $row['btn_url']    = $this->url($s['btn_url'] ?? '');
                $row['btn2_label'] = $this->text($s['btn2_label'] ?? '', 60);
                $row['btn2_url']   = $this->url($s['btn2_url'] ?? '');
            }

            $out[] = $row;
            if (count($out) >= 20) { break; } // hard cap
        }

        return $out;
    }

    private function text($v, int $max = 200): string
    {
        return \Illuminate\Support\Str::limit(trim(strip_tags((string) $v)), $max, '');
    }

    private function hex($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '';
    }

    private function url($v): string
    {
        $v = trim((string) $v);
        if ($v === '') { return ''; }
        // allow relative (/book, #anchor) or http(s) absolute
        if (str_starts_with($v, '/') || str_starts_with($v, '#')) { return $v; }
        return filter_var($v, FILTER_VALIDATE_URL) ? $v : '';
    }

    private function clampInt($v, int $min, int $max): int
    {
        $n = (int) $v;
        return max($min, min($max, $n));
    }
}
