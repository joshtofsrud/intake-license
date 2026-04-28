<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-resource daily appointment cap. Drop-off mode: each resource has its own
 * quota; effective shop-wide capacity = sum of resource caps, optionally
 * overridden by capacity_rule.max_appointments. Time-slot mode: not used
 * directly (grid math + max_appointments override govern), but kept on the
 * model so a future feature could expose per-resource throttling.
 *
 * NULL = no per-resource cap (resource can take unlimited bookings on the day,
 * subject to whatever grid/shop-wide constraints apply).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_resources', function (Blueprint $t) {
            $t->unsignedSmallInteger('max_appointments_per_day')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_resources', function (Blueprint $t) {
            $t->dropColumn('max_appointments_per_day');
        });
    }
};
