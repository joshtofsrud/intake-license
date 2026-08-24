#!/usr/bin/env python3
"""Tile dashboard — part 1: registry, preference, controller.

STRICTLY ADDITIVE. The existing dashboard, its four zone partials,
dashboard.css and dashboard-tiles.css are not touched. Overview renders
byte-for-byte what it renders today; Tiles is a second view for people
who want a way in rather than a report. If the preference is absent or
anything here fails, you get today's dashboard.

The registry is new rather than a refactor of _zone_launcher: the
launcher stays hardcoded and untouched. Two views with different jobs
isn't duplication to collapse later — it's the design Josh asked for.

Run from repo root: python3 apply-tile-dashboard-core.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

# ============================================================
# 1) Migration — per-user view choice + tile layout
# ============================================================
mig = 'database/migrations/2026_08_23_140000_add_dashboard_prefs_to_tenant_users.php'
if os.path.exists(os.path.join(ROOT, mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-TILES — both nullable and additive. A null dashboard_view means
// the existing Overview dashboard, which is what every current user gets
// until they choose otherwise.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('dashboard_view', 16)->nullable()->after('admin_theme');
            $table->json('dashboard_tiles')->nullable()->after('dashboard_view');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn(['dashboard_view', 'dashboard_tiles']);
        });
    }
};
""")
    print("OK: migration")

# Anchored on 'last_login_at', which is present regardless of whether the
# timeclock-exemption patch has run — otherwise this quietly depends on
# another patch's output and fails on a clean checkout.
sub('app/Models/Tenant/TenantUser.php',
    "'last_login_at','pin_hash'",
    "'last_login_at','dashboard_view','dashboard_tiles','pin_hash'",  # MARKER-TILES
    "model: fillable")

sub('app/Models/Tenant/TenantUser.php',
    "'last_login_at' => 'datetime',",
    "'last_login_at' => 'datetime', 'dashboard_tiles' => 'array',",
    "model: cast")

# ============================================================
# 2) The registry
# ============================================================
newfile('app/Support/DashboardTiles.php', """<?php

namespace App\\Support;

use App\\Models\\Tenant;
use App\\Models\\Tenant\\TenantUser;

/**
 * MARKER-TILES — tile definitions for the simplified dashboard.
 *
 * Deliberately separate from _zone_launcher.blade.php, which stays
 * hardcoded and untouched: the Overview dashboard is staying permanently,
 * so the two views serve different jobs rather than one being a
 * transitional copy of the other.
 *
 * A tile is data — key, label, route, icon, tone, and where its sub-stat
 * comes from — so a user can reorder and hide them, and so a new feature
 * appears here by adding one entry.
 */
class DashboardTiles
{
    /**
     * `gate` names a Tenant accessor that must be truthy for the tile to
     * exist at all. Gates ALWAYS win over a saved layout: an add-on that
     * lapses removes the tile no matter what the user arranged.
     */
    public static function definitions(): array
    {
        return [
            'calendar' => [
                'label' => 'Calendar', 'route' => 'tenant.calendar.index', 'tone' => 'green',
                'stat'  => fn ($L) => (($L['calendar']['today_count'] ?? 0)) . ' booked today',
                'icon'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
            ],
            'register' => [
                'label' => 'Register', 'route' => 'tenant.register.index', 'tone' => 'teal',
                'stat'  => fn ($L) => format_money($L['register']['today_total_cents'] ?? 0) . ' today',
                'icon'  => '<rect x="2" y="4" width="20" height="14" rx="2"/><path d="M6 9h12M6 13h7"/>',
            ],
            'customers' => [
                'label' => 'Customers', 'route' => 'tenant.customers.index', 'tone' => 'blue',
                'stat'  => fn ($L) => number_format($L['customers']['count'] ?? 0) . ' in database',
                'icon'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
            ],
            'appointments' => [
                'label' => 'Appointments', 'route' => 'tenant.appointments.index', 'tone' => 'indigo',
                'stat'  => fn ($L) => 'All bookings · open work',
                'icon'  => '<path d="M9 11l3 3 5-6"/><rect x="3" y="4" width="18" height="17" rx="2"/>',
            ],
            'waitlist' => [
                'label' => 'Waitlist', 'route' => 'tenant.waitlist.index', 'tone' => 'slate',
                'stat'  => fn ($L) => ($L['waitlist']['count'] ?? 0) > 0
                            ? $L['waitlist']['count'] . ' waiting' : 'No one waiting',
                'icon'  => '<path d="M12 2v6l4 2"/><circle cx="12" cy="14" r="8"/>',
            ],
            'inventory' => [
                'label' => 'Inventory', 'route' => 'tenant.inventory.index', 'tone' => 'indigo',
                'stat'  => fn ($L) => number_format($L['inventory']['active_count'] ?? 0) . ' active items',
                'icon'  => '<path d="M12 2l9 5v10l-9 5-9-5V7z"/><path d="M12 12l9-5M12 12v10M12 12L3 7"/>',
            ],
            'special_orders' => [
                'label' => 'Special orders', 'route' => 'tenant.special-orders.index', 'tone' => 'rust',
                'stat'  => fn ($L) => ($L['special_orders']['arrived_count'] ?? 0) > 0
                            ? $L['special_orders']['arrived_count'] . ' arrived' : 'All current',
                'icon'  => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4"/>',
            ],
            'rentals' => [
                'label' => 'Rentals', 'route' => 'tenant.rentals.desk', 'tone' => 'plum',
                'gate'  => 'rentals_enabled',
                'stat'  => fn ($L) => 'Desk · fleet · bookings',
                'icon'  => '<circle cx="6" cy="17" r="3.5"/><circle cx="18" cy="17" r="3.5"/><path d="M6 17l4-8h5"/>',
            ],
            'inbox' => [
                'label' => 'Inbox', 'route' => 'tenant.inbox.index', 'tone' => 'moss',
                'stat'  => fn ($L) => 'Messages from customers',
                'icon'  => '<path d="M4 4h16v13H7l-3 3z"/>',
            ],
            'reports' => [
                'label' => 'Reports', 'route' => 'tenant.reports.index', 'tone' => 'ink',
                'stat'  => fn ($L) => 'Operations · customers · retail',
                'icon'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            ],
            'services' => [
                'label' => 'Services', 'route' => 'tenant.services.index', 'tone' => 'ink',
                'stat'  => fn ($L) => ($L['services']['count'] ?? 0) . ' active',
                'icon'  => '<path d="M14 4l6 6-9 9H5v-6z"/>',
            ],
            'resources' => [
                'label' => 'Resources', 'route' => 'tenant.resources.index', 'tone' => 'ink',
                'stat'  => fn ($L) => ($L['resources']['count'] ?? 0) . ' stations · '
                            . ($L['resources']['staff_count'] ?? 0) . ' staff',
                'icon'  => '<circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="10" r="2.5"/><path d="M2 20v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/>',
            ],
            'pages' => [
                'label' => 'Pages', 'route' => 'tenant.pages.index', 'tone' => 'ink',
                'stat'  => fn ($L) => ($L['pages']['published_count'] ?? 0) . ' live',
                'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>',
            ],
            'gift_cards' => [
                'label' => 'Gift cards', 'route' => 'tenant.gift-cards.index', 'tone' => 'ink',
                'gate'  => 'gift_cards_visible',
                'stat'  => fn ($L) => 'Sold · redeemed · balances',
                'icon'  => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M2 12h20M12 7v14"/>',
            ],
            'team' => [
                'label' => 'Team', 'route' => 'tenant.team.index', 'tone' => 'ink',
                'stat'  => fn ($L) => ($L['resources']['staff_count'] ?? 0) . ' staff',
                'icon'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/><circle cx="18" cy="9" r="2.5"/>',
            ],
            'settings' => [
                'label' => 'Settings', 'route' => 'tenant.settings.index', 'tone' => 'ink',
                'stat'  => fn ($L) => 'Shop · branding · billing',
                'icon'  => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
            ],
        ];
    }

    /** Tiles this tenant is entitled to, in registry order. */
    public static function available(?Tenant $tenant): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $gate = $def['gate'] ?? null;
            if ($gate) {
                try {
                    if (! $tenant || ! $tenant->{$gate}) continue;
                } catch (\\Throwable $e) {
                    continue; // an accessor that doesn't exist hides the tile
                }
            }
            // A route that isn't registered would fatal the whole dashboard.
            if (! \\Illuminate\\Support\\Facades\\Route::has($def['route'])) continue;
            $out[$key] = $def;
        }
        return $out;
    }

    /**
     * The user's arrangement, reconciled against what actually exists:
     * saved order first, then anything new appended, minus anything they
     * hid, minus anything they're no longer entitled to.
     */
    public static function layout(?Tenant $tenant, ?TenantUser $user): array
    {
        $available = self::available($tenant);
        $saved     = is_array($user?->dashboard_tiles) ? $user->dashboard_tiles : [];
        $order     = array_values(array_filter((array) ($saved['order'] ?? []), 'is_string'));
        $hidden    = array_values(array_filter((array) ($saved['hidden'] ?? []), 'is_string'));

        $visible = [];
        foreach ($order as $key) {
            if (isset($available[$key]) && ! in_array($key, $hidden, true)) {
                $visible[$key] = $available[$key];
            }
        }
        // Anything the registry gained since they last saved.
        foreach ($available as $key => $def) {
            if (! isset($visible[$key]) && ! in_array($key, $hidden, true)) {
                $visible[$key] = $def;
            }
        }

        $hiddenTiles = [];
        foreach ($hidden as $key) {
            if (isset($available[$key])) $hiddenTiles[$key] = $available[$key];
        }

        return ['visible' => $visible, 'hidden' => $hiddenTiles];
    }
}
""", "DashboardTiles registry")

# ============================================================
# 3) Controller — branch on the preference, save endpoints
# ============================================================
sub('app/Http/Controllers/Tenant/DashboardController.php',
    """        return view('tenant.dashboard', $data);
    }""",
    """        // MARKER-TILES — a second view for people who want a way in rather
        // than a report. Overview is unchanged and remains the default.
        $user = \\Illuminate\\Support\\Facades\\Auth::guard('tenant')->user();
        if (($user->dashboard_view ?? 'overview') === 'tiles') {
            $data['tiles'] = \\App\\Support\\DashboardTiles::layout($tenant, $user);
            return view('tenant.dashboard-tiles', $data);
        }

        return view('tenant.dashboard', $data);
    }

    /** MARKER-TILES — flip between Overview and Tiles. */
    public function setView(\\Illuminate\\Http\\Request $request)
    {
        $data = $request->validate(['view' => 'required|in:overview,tiles']);
        \\Illuminate\\Support\\Facades\\Auth::guard('tenant')->user()
            ->update(['dashboard_view' => $data['view']]);

        return redirect()->route('tenant.dashboard');
    }

    /** MARKER-TILES — save one user's tile arrangement. */
    public function saveTiles(\\Illuminate\\Http\\Request $request)
    {
        $data = $request->validate([
            'order'    => 'nullable|array|max:60',
            'order.*'  => 'string|max:40',
            'hidden'   => 'nullable|array|max:60',
            'hidden.*' => 'string|max:40',
        ]);

        // Only keys the registry actually knows — a stale tab or a hand-made
        // request can't stuff arbitrary strings into the column.
        $known = array_keys(\\App\\Support\\DashboardTiles::definitions());
        $clean = [
            'order'  => array_values(array_intersect($data['order']  ?? [], $known)),
            'hidden' => array_values(array_intersect($data['hidden'] ?? [], $known)),
        ];

        \\Illuminate\\Support\\Facades\\Auth::guard('tenant')->user()
            ->update(['dashboard_tiles' => $clean]);

        return response()->json(['ok' => true]);
    }

    /** MARKER-TILES — back to the registry's own order, everything shown. */
    public function resetTiles()
    {
        \\Illuminate\\Support\\Facades\\Auth::guard('tenant')->user()
            ->update(['dashboard_tiles' => null]);

        return redirect()->route('tenant.dashboard')->with('success', 'Tiles reset.');
    }""",
    "controller: tiles branch + endpoints")

print("\\nPart 1 of 2 done — run apply-tile-dashboard-view.py next.")
