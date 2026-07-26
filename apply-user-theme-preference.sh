#!/bin/bash
# user-theme-preference — light/dark becomes a per-person choice instead of a
# shop-wide one.
#   Today the sidebar toggle POSTs to Settings -> appearance, which writes
#   tenants.settings['admin_theme']. Every staff member on that tenant shares
#   it, so one person switching to light flips the whole shop.
#   After this patch:
#     · tenant_users.admin_theme (nullable char) holds the person's choice
#     · ApplyTenantTheme reads the signed-in user first, falls back to the
#       tenant setting, then to 'c' (dark)
#     · the sidebar toggle POSTs to a new tenant.theme.set route that writes
#       ONLY the user column — it never touches tenant settings again
#   Nobody sees a change until they toggle once: a null column inherits the
#   shop's existing stored theme, so current shops look identical on deploy.
#   The tenant setting stays as a silent fallback with no UI (there is no
#   Appearance tab — SettingsController::updateAppearance is left in place
#   but is now unreachable from the sidebar).
#   On PIN-tier shared screens the theme follows whoever switches in, which
#   is the point, but it is visible: the screen will flip if two people have
#   picked different themes.
# NEW ROUTE (tenant.theme.set). Server: MIGRATION REQUIRED, then optimize:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-USER-THEME-PREF" app/Http/Middleware/ApplyTenantTheme.php; then
  echo "user-theme-preference already applied — aborting."; exit 1
fi

# ---------------------------------------------------------------- migration
cat > 'database/migrations/2026_07_24_000004_add_admin_theme_to_tenant_users.php' <<'UTP_0_EOF'
<?php

// MARKER-USER-THEME-PREF — per-person light/dark. Nullable on purpose:
// null means "no choice made", which inherits the tenant's stored theme, so
// existing staff see exactly what they saw before this shipped.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $t) {
            // 'b' (light) | 'c' (dark) | null (use the shop default)
            $t->string('admin_theme', 1)->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $t) {
            $t->dropColumn('admin_theme');
        });
    }
};
UTP_0_EOF

# ---------------------------------------------------------------- controller
cat > 'app/Http/Controllers/Tenant/ThemeController.php' <<'UTP_1_EOF'
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
UTP_1_EOF

# ---------------------------------------------------------------- model
python3 - <<'UTP_2_EOF'
import io
p = 'app/Models/Tenant/TenantUser.php'
s = io.open(p, encoding='utf-8').read()
old = "'role','role_id','is_active'"
new = "'role','role_id','admin_theme','is_active'"  # MARKER-USER-THEME-PREF
assert s.count(old) == 1, s.count(old)
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('TenantUser fillable ok')
UTP_2_EOF

# ---------------------------------------------------------------- middleware
python3 - <<'UTP_3_EOF'
import io
p = 'app/Http/Middleware/ApplyTenantTheme.php'
s = io.open(p, encoding='utf-8').read()

old = """use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\View;"""
new = """use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\View;"""
assert s.count(old) == 1
s = s.replace(old, new)

old = """        $theme = 'c'; // default \u2014 dark premium

        if ($tenant) {
            $settings = $tenant->settings ?? [];
            $stored   = $settings['admin_theme'] ?? 'c';
            $theme    = in_array($stored, ['b', 'c']) ? $stored : 'c';
        }
"""
assert s.count(old) == 1, s.count(old)
new = """        $theme = 'c'; // default \u2014 dark premium

        if ($tenant) {
            $settings = $tenant->settings ?? [];
            $stored   = $settings['admin_theme'] ?? 'c';
            $theme    = in_array($stored, ['b', 'c']) ? $stored : 'c';
        }

        // MARKER-USER-THEME-PREF \u2014 the signed-in person's own choice wins.
        // Null means they have never picked, so they inherit the shop value
        // resolved above. This middleware also runs on the staff-switcher
        // group where nobody is authenticated yet; there the shop value is
        // all we have, which is correct for a locked screen.
        $user = Auth::guard('tenant')->user();
        if ($user && in_array($user->admin_theme, ['b', 'c'], true)) {
            $theme = $user->admin_theme;
        }
"""
s = s.replace(old, new)
io.open(p, 'w', encoding='utf-8').write(s)
print('ApplyTenantTheme ok')
UTP_3_EOF

# ---------------------------------------------------------------- route
python3 - <<'UTP_4_EOF'
import io
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()
old = """            Route::post('/pin/setup',        [TenantControllers\\PinGateController::class, 'setupPin'])->name('pin.setup');
"""
assert s.count(old) == 1, s.count(old)
new = old + """
            // MARKER-USER-THEME-PREF \u2014 per-person light/dark. Sits above the
            // location gate so the toggle works before a location is picked.
            Route::post('/theme',            [TenantControllers\\ThemeController::class, 'set'])->name('theme.set');
"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('route ok')
UTP_4_EOF

# ---------------------------------------------------------------- sidebar
python3 - <<'UTP_5_EOF'
import io
p = 'resources/views/layouts/tenant/_sidebar.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """        {{-- MARKER-PATCH-150-POLISH-B \u2014 theme toggle (light/dark) --}}
        <form method="POST" action="{{ route('tenant.settings.update') }}" id="theme-toggle-form" style="margin:0">
          @csrf @method('PATCH')
          <input type="hidden" name="tab" value="appearance">
          <input type="hidden" name="admin_theme" id="theme-toggle-value" value="{{ $adminTheme === 'c' ? 'b' : 'c' }}">"""
assert s.count(old) == 1, s.count(old)
new = """        {{-- MARKER-USER-THEME-PREF \u2014 theme toggle writes THIS person's
             preference only. It used to POST to Settings->appearance, which
             stored the theme on the tenant and flipped it for the whole shop. --}}
        <form method="POST" action="{{ route('tenant.theme.set') }}" id="theme-toggle-form" style="margin:0">
          @csrf
          <input type="hidden" name="admin_theme" id="theme-toggle-value" value="{{ $adminTheme === 'c' ? 'b' : 'c' }}">"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('sidebar ok')
UTP_5_EOF

php -l app/Http/Controllers/Tenant/ThemeController.php
php -l app/Http/Middleware/ApplyTenantTheme.php
php -l routes/web.php
php -l app/Models/Tenant/TenantUser.php

echo
echo "user-theme-preference applied."
