<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot truncate of tenant_capacity_rules ahead of the capacity rebuild.
 * No real customers yet; demo tenants (blueridge, test) reseed via
 * DemoSeeder which will run after this migration in the deploy chain.
 *
 * If we had real customers this would be a data migration script that mapped
 * existing rules into the new shape. Today it's a clean slate.
 */
return new class extends Migration {
    public function up(): void
    {
        // Truncate doesn't fire model events but we don't have any here.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('tenant_capacity_rules')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        // No reversal — the rows are gone. Reseed via DemoSeeder.
    }
};
