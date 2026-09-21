<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-APPT-AUTOLOAD — keep the booking screen's day counts current. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('availability_auto_refresh')->default(true)->after('staff_capacity_policy');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('availability_auto_refresh');
        });
    }
};
