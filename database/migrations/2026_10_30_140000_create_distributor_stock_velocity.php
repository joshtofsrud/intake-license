<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-VELOCITY: distributor stock velocity.
 *
 * distributor_stock_summary — one row per distributor item, updated in place
 * on every reading. Holds the current stock, a time-weighted average daily
 * drop (drop_units / observed_days, both decayed with a 30-day memory), the
 * last restock and the next arrival date. Constant size: it never grows past
 * the number of items read.
 *
 * distributor_stock_changes — one row per reading where the quantity CHANGED.
 * Kept 60 days (distributors:velocity-prune), for charts and so the rate can
 * be recalculated if the formula changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distributor_stock_summary')) {
            Schema::create('distributor_stock_summary', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('distributor_code', 32);
                $t->string('distributor_variant_no', 64);
                $t->uuid('distributor_catalog_id')->nullable();
                $t->integer('avail')->nullable();
                $t->integer('prev_avail')->nullable();
                $t->decimal('drop_units', 14, 3)->default(0);
                $t->decimal('observed_days', 10, 3)->default(0);
                $t->decimal('rate_per_day', 10, 3)->nullable();
                $t->decimal('days_left', 10, 1)->nullable();
                $t->unsignedInteger('readings')->default(0);
                $t->unsignedInteger('changes')->default(0);
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_reading_at')->nullable();
                $t->timestamp('last_change_at')->nullable();
                $t->timestamp('last_restock_at')->nullable();
                $t->date('next_arrival_on')->nullable();
                // the reading that existed before this build, kept for comparison
                $t->integer('baseline_avail')->nullable();
                $t->timestamp('baseline_at')->nullable();
                $t->timestamps();

                $t->unique(['distributor_code', 'distributor_variant_no'], 'dss_item_unique');
                $t->index(['days_left'], 'dss_days_left_idx');
                $t->index(['rate_per_day'], 'dss_rate_idx');
                $t->index(['next_arrival_on'], 'dss_arrival_idx');
                $t->index(['distributor_catalog_id'], 'dss_catalog_idx');
            });
        }

        if (! Schema::hasTable('distributor_stock_changes')) {
            Schema::create('distributor_stock_changes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('distributor_code', 32);
                $t->string('distributor_variant_no', 64);
                $t->integer('avail')->nullable();
                $t->integer('prev_avail')->nullable();
                $t->timestamp('recorded_at');

                $t->index(['distributor_code', 'distributor_variant_no', 'recorded_at'], 'dsc_item_time_idx');
                $t->index(['recorded_at'], 'dsc_time_idx');
            });
        }

        if (! Schema::hasColumn('platform_settings', 'velocity_settings')) {
            Schema::table('platform_settings', function (Blueprint $t) {
                $t->json('velocity_settings')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_stock_changes');
        Schema::dropIfExists('distributor_stock_summary');
        if (Schema::hasColumn('platform_settings', 'velocity_settings')) {
            Schema::table('platform_settings', function (Blueprint $t) {
                $t->dropColumn('velocity_settings');
            });
        }
    }
};
