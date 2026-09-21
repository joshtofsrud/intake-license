<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-APPT-OVERRIDE — an override that stays visible.
 *
 * Without these columns an over-capacity booking is indistinguishable from an
 * ordinary one the moment it is saved, and the day quietly reads as normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->boolean('override_short_notice')->default(false)->after('status');
            $t->boolean('override_capacity')->default(false)->after('override_short_notice');
            $t->string('override_reason', 200)->nullable()->after('override_capacity');
            $t->uuid('override_by_user_id')->nullable()->after('override_reason');
            $t->index(['tenant_id', 'appointment_date', 'override_capacity'], 'ta_over_cap_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropIndex('ta_over_cap_idx');
            $t->dropColumn(['override_short_notice', 'override_capacity', 'override_reason', 'override_by_user_id']);
        });
    }
};
