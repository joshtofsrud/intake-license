<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\ColorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    public function index()
    {
        return view('tenant.branding.index');
    }

    public function update(Request $request)
    {
        $tenant = tenant();
        $tab    = $request->input('tab', 'appearance');

        if ($tab === 'appearance') {
            $request->validate([
                'name'         => ['required', 'string', 'max:255'],
                'tagline'      => ['nullable', 'string', 'max:255'],
                'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'text_color'   => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'bg_color'     => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'font_heading' => ['nullable', 'string', 'max:100'],
                'font_body'    => ['nullable', 'string', 'max:100'],
                'admin_theme'  => ['nullable', 'in:a,b,c'],
            ]);

            $data = $request->only([
                'name', 'tagline', 'accent_color', 'text_color',
                'bg_color', 'font_heading', 'font_body',
            ]);

            if ($request->hasFile('logo')) {
                $request->validate(['logo' => ['image', 'max:2048']]);
                $path = $request->file('logo')->store("tenants/{$tenant->id}/logo", 'public');
                $data['logo_url'] = asset('storage/' . $path);
            }

            if ($request->hasFile('logo_light')) {
                $request->validate(['logo_light' => ['image', 'max:2048']]);
                $path = $request->file('logo_light')->store("tenants/{$tenant->id}/logo", 'public');
                $data['logo_light_url'] = asset('storage/' . $path);
            }

            if ($request->hasFile('favicon')) {
                $request->validate(['favicon' => ['image', 'max:512']]);
                $path = $request->file('favicon')->store("tenants/{$tenant->id}/favicon", 'public');
                $data['favicon_url'] = asset('storage/' . $path);
            }

            $tenant->update($data);

            if ($request->filled('admin_theme')) {
                $settings = $tenant->settings ?? [];
                $settings['admin_theme'] = $request->input('admin_theme');
                $tenant->update(['settings' => $settings]);
            }

            return back()->with('success', 'Appearance saved.');
        }

        if ($tab === 'email') {
            $request->validate([
                'email_from_name'    => ['nullable', 'string', 'max:255'],
                'email_from_address' => ['nullable', 'email', 'max:255'],
                'email_reply_to'     => ['nullable', 'email', 'max:255'],
                'notification_email' => ['nullable', 'email', 'max:255'],
            ]);
            $tenant->update($request->only([
                'email_from_name', 'email_from_address',
                'email_reply_to', 'notification_email',
            ]));
            return back()->with('success', 'Email settings saved.');
        }

        return back()->with('error', 'Unknown tab.');
    }
}
