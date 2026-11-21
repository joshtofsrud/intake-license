<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ThemeController
 *
 * Writes the signed-in staff member's light/dark preference and nothing
 * else. Deliberately does NOT touch tenants.settings: that value is now
 * only a fallback for people who have never picked, and one person's
 * choice must never move it for the shop.
 */
class ThemeController extends Controller
{
    public function set(Request $request)
    {
        $request->validate([
            'admin_theme' => ['required', 'in:b,c'],
        ]);

        $user = Auth::guard('tenant')->user();

        if ($user) {
            $user->admin_theme = $request->input('admin_theme');
            $user->save();
        }

        return back();
    }

    /** MARKER-FLOAT-SIDEBAR: this person's sidebar style, floating or locked. Nobody else's changes. */
    public function sidebar(Request $request)
    {
        $request->validate([
            'admin_sidebar' => ['required', 'in:float,locked'],
        ]);

        $user = Auth::guard('tenant')->user();

        if ($user) {
            $user->admin_sidebar = $request->input('admin_sidebar');
            $user->save();
        }

        return back();
    }
    /**
     * MARKER-NAV-PINS: this person's pinned menu items, in the order given. Only routes that
     * are in the menu are kept; what they can actually see is decided when the menu is drawn.
     */
    public function navPins(Request $request)
    {
        $data = $request->validate([
            'pins'   => ['present', 'array', 'max:40'],
            'pins.*' => ['string', 'max:120'],
        ]);
        $user = Auth::guard('tenant')->user();
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'Sign in again to change your menu.'], 401);
        }
        if (function_exists('is_impersonating') && is_impersonating()) {
            return response()->json(['ok' => false, 'error' => 'Pins aren\'t saved while you are signed in as someone else.'], 422);
        }
        $known = array_column(\App\Support\TenantNav::items(), 'route');
        $pins = array_values(array_unique(array_filter($data['pins'], fn ($r) => in_array($r, $known, true))));
        $user->nav_pins = $pins ?: null;
        $user->save();

        return response()->json(['ok' => true, 'pins' => $pins]);
    }
}
