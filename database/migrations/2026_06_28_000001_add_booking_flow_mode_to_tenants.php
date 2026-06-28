<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-FLOW-1 — booking flow mode: advanced (current) | simple | choice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // 'advanced' keeps every existing tenant on the current 6-step flow.
            $table->string('booking_flow_mode', 16)->default('advanced')->after('booking_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('booking_flow_mode');
        });
    }
};
