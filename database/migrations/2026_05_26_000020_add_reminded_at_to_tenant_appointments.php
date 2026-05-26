<?php
// MARKER-PATCH-154

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add reminded_at to tenant_appointments.
 *
 * 24-hour reminder cron stamps this column when it sends the
 * reminder. The "is this row reminder-eligible?" query filters on
 * (reminded_at IS NULL) so the reminder fires exactly once per
 * appointment, ever.
 *
 * If a tenant reschedules an appointment to a later date, the row
 * is still marked reminded_at = old time. v1 policy: no re-reminder
 * on reschedule. Edge case, can revisit if shop owners ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('staff_notes');
            // Partial-index target: cron scans rows with reminded_at IS NULL,
            // bounded by appointment_date ~24h ahead. Composite covers both.
            $table->index(['tenant_id', 'appointment_date', 'reminded_at'], 'ta_reminder_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropIndex('ta_reminder_lookup');
            $table->dropColumn('reminded_at');
        });
    }
};
