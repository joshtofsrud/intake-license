<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-452 — per-step funnel granularity for booking drop-off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_funnel_events', function (Blueprint $table) {
            // Wizard step key for booking_step events, e.g. "03 Your details".
            // Nullable: only booking_step rows populate it. ADD COLUMN nullable
            // is an instant DDL on MySQL 8 — no table rewrite, no lock.
            $table->string('step', 48)->nullable()->after('device');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_funnel_events', function (Blueprint $table) {
            $table->dropColumn('step');
        });
    }
};
