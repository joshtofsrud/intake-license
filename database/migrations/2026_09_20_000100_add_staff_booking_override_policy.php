<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BOOKING-OVERRIDE — what staff may do that customers may not.
 *
 * Defaults are chosen to change nothing on deploy: 'follow' keeps staff on the
 * customer notice rule, and 'block' keeps a full day full. A shop opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            // follow | warn | silent
            $t->string('staff_notice_policy', 16)->default('follow')->after('min_notice_hours');
            // block | marked
            $t->string('staff_capacity_policy', 16)->default('block')->after('staff_notice_policy');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['staff_notice_policy', 'staff_capacity_policy']);
        });
    }
};
