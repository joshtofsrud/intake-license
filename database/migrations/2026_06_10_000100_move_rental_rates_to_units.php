<?php
// MARKER-PATCH-218B — rates live on the unit, not the category.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. The override columns become THE rate columns.
        Schema::table('tenant_rental_units', function (Blueprint $t) {
            $t->renameColumn('hourly_rate_cents_override', 'hourly_rate_cents');
            $t->renameColumn('daily_rate_cents_override', 'daily_rate_cents');
            $t->renameColumn('weekend_rate_cents_override', 'weekend_rate_cents');
            $t->renameColumn('deposit_cents_override', 'deposit_cents');
        });

        // 2. Preserve anything entered during fleet testing: copy category
        //    rates down into units that don't have their own value.
        //    Category deposit default was 0 — NULLIF keeps that from
        //    stamping zeros over genuinely-unset unit deposits.
        DB::statement('
            UPDATE tenant_rental_units u
            JOIN tenant_rental_categories c ON c.id = u.category_id
            SET u.hourly_rate_cents  = COALESCE(u.hourly_rate_cents,  c.hourly_rate_cents),
                u.daily_rate_cents   = COALESCE(u.daily_rate_cents,   c.daily_rate_cents),
                u.weekend_rate_cents = COALESCE(u.weekend_rate_cents, c.weekend_rate_cents),
                u.deposit_cents      = COALESCE(u.deposit_cents,      NULLIF(c.deposit_cents, 0))
        ');

        // 3. Categories are grouping only from here on.
        Schema::table('tenant_rental_categories', function (Blueprint $t) {
            $t->dropColumn(['hourly_rate_cents', 'daily_rate_cents', 'weekend_rate_cents', 'deposit_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rental_categories', function (Blueprint $t) {
            $t->unsignedInteger('hourly_rate_cents')->nullable();
            $t->unsignedInteger('daily_rate_cents')->nullable();
            $t->unsignedInteger('weekend_rate_cents')->nullable();
            $t->unsignedInteger('deposit_cents')->default(0);
        });

        Schema::table('tenant_rental_units', function (Blueprint $t) {
            $t->renameColumn('hourly_rate_cents', 'hourly_rate_cents_override');
            $t->renameColumn('daily_rate_cents', 'daily_rate_cents_override');
            $t->renameColumn('weekend_rate_cents', 'weekend_rate_cents_override');
            $t->renameColumn('deposit_cents', 'deposit_cents_override');
        });
    }
};
