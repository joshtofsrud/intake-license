<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ThemeController — MARKER-USER-THEME-PREF
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
}
