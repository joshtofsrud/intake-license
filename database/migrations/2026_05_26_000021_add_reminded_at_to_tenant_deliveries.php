<?php
// MARKER-PATCH-155

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add reminded_at to tenant_deliveries — idempotence guard for the
 * 24-hour reminder cron. Same role as the column on tenant_appointments
 * (added in patch 154).
 *
 * Composite index covers the cron's lookup pattern:
 *   WHERE tenant_id = ? AND scheduled_at BETWEEN ... AND reminded_at IS NULL
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_deliveries', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('cancelled_at');
            $table->index(['tenant_id', 'scheduled_at', 'reminded_at'], 'td_reminder_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_deliveries', function (Blueprint $table) {
            $table->dropIndex('td_reminder_lookup');
            $table->dropColumn('reminded_at');
        });
    }
};
