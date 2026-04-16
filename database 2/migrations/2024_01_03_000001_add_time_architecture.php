<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Tenants — booking mode
        // ----------------------------------------------------------------
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('booking_mode', ['drop_off', 'time_slots'])
                  ->default('drop_off')
                  ->after('min_notice_hours');
        });

        // ----------------------------------------------------------------
        // Service items — duration, buffer, slot weight
        // Always stored regardless of mode so switching is seamless
        // ----------------------------------------------------------------
        Schema::table('tenant_service_items', function (Blueprint $table) {
            // Duration in minutes (time_slots mode — required)
            $table->unsignedSmallInteger('duration_minutes')
                  ->default(60)
                  ->after('is_active');

            // Buffer time after this service before next slot
            $table->unsignedSmallInteger('buffer_minutes')
                  ->default(0)
                  ->after('duration_minutes');

            // Slot weight (drop_off mode — how many capacity slots this job uses)
            // 1 = normal job, 2 = bigger job, 3 = large job, 4 = full-day job
            $table->unsignedTinyInteger('slot_weight')
                  ->default(1)
                  ->after('buffer_minutes');
        });

        // ----------------------------------------------------------------
        // Add-ons — duration and slot weight contribution
        // ----------------------------------------------------------------
        Schema::table('tenant_addons', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')
                  ->default(0)
                  ->after('price_cents');

            $table->unsignedTinyInteger('slot_weight')
                  ->default(0)
                  ->after('duration_minutes');
        });

        // ----------------------------------------------------------------
        // Capacity rules — time slot mode fields
        // ----------------------------------------------------------------
        Schema::table('tenant_capacity_rules', function (Blueprint $table) {
            // Daily open/close times for time_slots mode
            $table->time('open_time')
                  ->nullable()
                  ->after('max_appointments');

            $table->time('close_time')
                  ->nullable()
                  ->after('open_time');

            // How often slots start (in minutes) — 15, 30, 45, 60, 90, 120
            $table->unsignedSmallInteger('slot_interval_minutes')
                  ->default(60)
                  ->after('close_time');
        });

        // ----------------------------------------------------------------
        // Appointments — appointment time and slot weight
        // ----------------------------------------------------------------
        Schema::table('tenant_appointments', function (Blueprint $table) {
            // Specific appointment time (time_slots mode)
            $table->time('appointment_time')
                  ->nullable()
                  ->after('appointment_date');

            // Calculated end time (appointment_time + total duration)
            $table->time('appointment_end_time')
                  ->nullable()
                  ->after('appointment_time');

            // Total duration of this appointment in minutes
            $table->unsignedSmallInteger('total_duration_minutes')
                  ->default(0)
                  ->after('appointment_end_time');

            // Slot weight — how many capacity slots this appointment uses
            // Auto-calculated but admin can override (1-4)
            $table->unsignedTinyInteger('slot_weight')
                  ->default(1)
                  ->after('total_duration_minutes');

            // Track whether slot_weight was manually set by admin
            $table->boolean('slot_weight_overridden')
                  ->default(false)
                  ->after('slot_weight');

            // Auto-calculated slot weight (so admin can see what it was before override)
            $table->unsignedTinyInteger('slot_weight_auto')
                  ->default(1)
                  ->after('slot_weight_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropColumn([
                'appointment_time',
                'appointment_end_time',
                'total_duration_minutes',
                'slot_weight',
                'slot_weight_overridden',
                'slot_weight_auto',
            ]);
        });

        Schema::table('tenant_capacity_rules', function (Blueprint $table) {
            $table->dropColumn(['open_time', 'close_time', 'slot_interval_minutes']);
        });

        Schema::table('tenant_addons', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'slot_weight']);
        });

        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'buffer_minutes', 'slot_weight']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('booking_mode');
        });
    }
};
