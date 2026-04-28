<?php
/**
 * Destructive demo-tenant reset.
 *
 * Wipes ALL tenants from the database, then creates two fresh ones via
 * the same code path as production signup (TenantUserObserver fires, etc.):
 *   - blueridge.intake.works  (bike shop, drop-off mode)
 *   - bella.intake.works      (hair salon, time-slot mode)
 *
 * Run on the server via:
 *     php artisan tinker --execute="require '/var/www/intake/scripts/reset-demo-tenants.php';"
 *
 * REQUIRES MANUAL CONFIRMATION at runtime. Aborts if not in production-ish env
 * with $RESET_CONFIRM environment variable set.
 *
 * Why a script not a seeder: this is a one-shot operational tool, not part of
 * normal database setup. Putting it in scripts/ rather than seeders/ makes
 * it less likely to fire accidentally during fresh installs.
 */

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantResource;
use App\Services\Demo\DemoSeeder;
use App\Services\Demo\Industries\BikeShopData;
use App\Services\Demo\Industries\SalonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (getenv('RESET_CONFIRM') !== 'yes') {
    echo "ABORT: set RESET_CONFIRM=yes to actually run this script.\n";
    echo "Example: RESET_CONFIRM=yes php artisan tinker --execute=\"require '/var/www/intake/scripts/reset-demo-tenants.php';\"\n";
    return;
}

$logger = function ($msg) { echo $msg . "\n"; };

$logger("=== Demo tenant reset ===");

// ----------------------------------------------------------------------
// 1. Destroy all existing tenants. Cascade deletes via FK constraints.
// ----------------------------------------------------------------------
// CRITICAL: never delete the __platform tenant. It owns the marketing
// pages, public roadmap, public changelog. Filter by subdomain AND
// is_platform flag because either is authoritative.
$existing = Tenant::where('subdomain', '!=', '__platform')
    ->where(function ($q) {
        $q->where('is_platform', false)->orWhereNull('is_platform');
    })
    ->get();

$logger("Found " . $existing->count() . " demo tenants (excluding __platform).");
foreach ($existing as $t) {
    $logger("  Deleting tenant: {$t->subdomain} ({$t->name})");
    $t->forceDelete();
}
$logger("Demo tenants deleted. __platform preserved.");

// ----------------------------------------------------------------------
// 2. Create blueridge (bike shop, drop-off mode).
// ----------------------------------------------------------------------
$logger("\n--- Creating blueridge.intake.works ---");
$bikeTenant = Tenant::create([
    'id'                => (string) Str::uuid(),
    'subdomain'         => 'blueridge',
    'name'              => 'Blue Ridge Cyclery',
    'plan_tier'         => 'scale',
    'booking_mode'      => 'drop_off',
    'is_active'         => true,
    'onboarding_status' => 'complete',
    'onboarded_at'      => now(),
    'timezone'          => 'America/New_York',
    'currency'          => 'USD',
    'currency_symbol'   => '$',
    'email_from_name'   => 'Blue Ridge Cyclery',
    'email_from_address'=> 'noreply@intake.works',
    'booking_window_days' => 60,
    'min_notice_hours'    => 24,
]);

$bikeSeeder = new DemoSeeder(new BikeShopData(), $logger);
$bikeSeeder->seed(
    $bikeTenant,
    'Maya Rodriguez',
    'owner@blueridge.intake.works',
    'demo-password-change-me'
);

// Per-resource daily caps for drop-off mode demonstration.
TenantResource::where('tenant_id', $bikeTenant->id)->each(function ($r, $idx) {
    $r->update(['max_appointments_per_day' => [8, 6, 4][$idx] ?? 6]);
});
$logger("  Resource caps set: 8 / 6 / 4 per day (sum = 18).");

// ----------------------------------------------------------------------
// 3. Create bella (hair salon, time-slot mode).
// ----------------------------------------------------------------------
$logger("\n--- Creating bella.intake.works ---");
$salonTenant = Tenant::create([
    'id'                => (string) Str::uuid(),
    'subdomain'         => 'bella',
    'name'              => 'Bella Salon',
    'plan_tier'         => 'scale',
    'booking_mode'      => 'time_slots',
    'is_active'         => true,
    'onboarding_status' => 'complete',
    'onboarded_at'      => now(),
    'timezone'          => 'America/New_York',
    'currency'          => 'USD',
    'currency_symbol'   => '$',
    'email_from_name'   => 'Bella Salon',
    'email_from_address'=> 'noreply@intake.works',
    'booking_window_days' => 30,
    'min_notice_hours'    => 4,
]);

$salonSeeder = new DemoSeeder(new SalonData(), $logger);
$salonSeeder->seed(
    $salonTenant,
    'Iris Kane',
    'owner@bella.intake.works',
    'demo-password-change-me'
);

// Time-slot mode: per-resource caps not strictly required (grid math governs)
// but populated anyway so the capacity admin page demonstrates the override
// path visually. Higher caps here \u2014 stylists do more bookings per day.
TenantResource::where('tenant_id', $salonTenant->id)->each(function ($r, $idx) {
    $r->update(['max_appointments_per_day' => [10, 8, 8, 6][$idx] ?? 8]);
});
$logger("  Resource caps set: 10 / 8 / 8 / 6 per day (sum = 32).");

$logger("\n=== Reset complete. ===");
$logger("blueridge.intake.works  (drop-off mode, owner@blueridge.intake.works)");
$logger("bella.intake.works      (time-slot mode, owner@bella.intake.works)");
$logger("Both passwords: demo-password-change-me");
