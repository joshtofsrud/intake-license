<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate existing has_waitlist_addon flags into the new tenant_addons framework.
 *
 * Runs AFTER 2026_04_20_000004 creates the tables.
 *
 * Non-destructive: reads has_waitlist_addon, creates tenant_addons rows with
 * source='staff_push', and logs the action. The tenants.has_waitlist_addon
 * column is NOT dropped here; a later cleanup migration will drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $waitlistExists = DB::table('addons')->where('code', 'waitlist')->exists();

        if (! $waitlistExists) {
            DB::table('addons')->insert([
                'code' => 'waitlist',
                'name' => 'Waitlist',
                'category' => 'operations',
                'description' => 'Broadcast-offer waitlist. Customer cancels open up to notified waitlist entries; first to confirm wins.',
                'tooltip' => 'When a customer cancels, interested customers get notified. First to confirm the slot gets it.',
                'price_cents' => 1900,
                'billing_cadence' => 'monthly',
                'included_in_plans' => json_encode(['branded', 'scale', 'custom']),
                'sort_order' => 50,
                'status' => 'active',
                'is_self_serve' => true,
                'is_new' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasColumn('tenants', 'has_waitlist_addon')) {
            return;
        }

        $tenants = DB::table('tenants')
            ->where('has_waitlist_addon', 1)
            ->select('id', 'plan_tier')
            ->get();

        foreach ($tenants as $tenant) {
            $alreadyMigrated = DB::table('tenant_addons')
                ->where('tenant_id', $tenant->id)
                ->where('addon_code', 'waitlist')
                ->whereIn('status', ['active', 'canceling', 'failed_payment'])
                ->exists();

            if ($alreadyMigrated) {
                continue;
            }

            $planIncludesWaitlist = in_array($tenant->plan_tier, ['branded', 'scale', 'custom'], true);

            if ($planIncludesWaitlist) {
                DB::table('addon_audit_log')->insert([
                    'tenant_id' => $tenant->id,
                    'addon_code' => 'waitlist',
                    'action' => 'stripe_synced',
                    'actor_type' => 'system',
                    'actor_label' => 'addon framework data migration',
                    'reason' => 'has_waitlist_addon=1 but plan tier includes waitlist; access granted via plan.',
                    'metadata' => json_encode(['legacy_flag' => true, 'plan_tier' => $tenant->plan_tier]),
                    'created_at' => now(),
                ]);
                continue;
            }

            DB::table('tenant_addons')->insert([
                'tenant_id' => $tenant->id,
                'addon_code' => 'waitlist',
                'source' => 'staff_push',
                'status' => 'active',
                'activated_at' => now(),
                'metadata' => json_encode([
                    'legacy_flag' => 'has_waitlist_addon',
                    'migrated_from' => 'tenants.has_waitlist_addon=1',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('addon_audit_log')->insert([
                'tenant_id' => $tenant->id,
                'addon_code' => 'waitlist',
                'action' => 'activated',
                'actor_type' => 'system',
                'actor_label' => 'addon framework data migration',
                'reason' => 'Migrated from legacy tenants.has_waitlist_addon flag',
                'metadata' => json_encode(['source' => 'staff_push', 'legacy_flag' => true]),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive up; no-op down.
    }
};
