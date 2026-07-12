<?php
// MARKER-PATCH-633 — end-of-day drawer reconciliation. One row per
// tenant-local day (per location when set): float + cash movement = expected,
// staff count the drawer, over/short recorded, day closed with a snapshot of
// the EOD numbers so the report is immutable history after close.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_drawer_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('location_id')->nullable();
            $table->date('day');                                // tenant-local date
            $table->integer('opening_float_cents')->default(0);
            $table->integer('paid_out_cents')->default(0);      // cash taken from drawer
            $table->string('paid_out_note', 200)->nullable();
            $table->integer('counted_cents')->nullable();       // staff count
            $table->integer('expected_cents')->nullable();      // snapshotted at close
            $table->integer('over_short_cents')->nullable();    // counted - expected
            $table->json('snapshot')->nullable();               // full EOD numbers at close
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'location_id', 'day'], 'tdd_tenant_loc_day_unique');
            $table->index(['tenant_id', 'day'], 'tdd_tenant_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_drawer_days');
    }
};

