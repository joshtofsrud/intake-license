<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-VELOCITY-SWEEP: when the whole-catalog sweep last read each item (only
 * sweep readings drive the pace once an item is swept), and a log of each
 * sweep run for the Distributor velocity page and the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distributor_stock_summary', 'last_sweep_at')) {
            Schema::table('distributor_stock_summary', function (Blueprint $t) {
                $t->timestamp('last_sweep_at')->nullable()->after('last_reading_at');
            });
        }
        if (! Schema::hasTable('distributor_velocity_runs')) {
            Schema::create('distributor_velocity_runs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->timestamp('started_at');
                $t->timestamp('finished_at')->nullable();
                $t->json('results')->nullable();
                $t->unsignedSmallInteger('problems')->default(0);
                $t->index('started_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_velocity_runs');
        if (Schema::hasColumn('distributor_stock_summary', 'last_sweep_at')) {
            Schema::table('distributor_stock_summary', function (Blueprint $t) { $t->dropColumn('last_sweep_at'); });
        }
    }
};
