<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename plan tiers: basic → starter. Add scale as new tier.
 * Previous enum: ('basic', 'branded', 'custom')
 * New enum:      ('starter', 'branded', 'scale', 'custom')
 *
 * Strategy: ALTER to add both old and new values, migrate data, then
 * ALTER again to remove the old value. Two statements so we don't lose data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Expand enum to include both old + new values temporarily.
        DB::statement("ALTER TABLE tenants MODIFY plan_tier ENUM('basic', 'starter', 'branded', 'scale', 'custom') NOT NULL DEFAULT 'starter'");

        // Step 2: Migrate existing data.
        DB::table('tenants')->where('plan_tier', 'basic')->update(['plan_tier' => 'starter']);

        // Step 3: Narrow enum to final set (drops 'basic').
        DB::statement("ALTER TABLE tenants MODIFY plan_tier ENUM('starter', 'branded', 'scale', 'custom') NOT NULL DEFAULT 'starter'");
    }

    public function down(): void
    {
        // Reverse: re-add 'basic', migrate starter+scale back, narrow.
        DB::statement("ALTER TABLE tenants MODIFY plan_tier ENUM('basic', 'starter', 'branded', 'scale', 'custom') NOT NULL DEFAULT 'basic'");
        DB::table('tenants')->where('plan_tier', 'starter')->update(['plan_tier' => 'basic']);
        DB::table('tenants')->where('plan_tier', 'scale')->update(['plan_tier' => 'branded']); // scale → branded as closest equivalent
        DB::statement("ALTER TABLE tenants MODIFY plan_tier ENUM('basic', 'branded', 'custom') NOT NULL DEFAULT 'basic'");
    }
};
