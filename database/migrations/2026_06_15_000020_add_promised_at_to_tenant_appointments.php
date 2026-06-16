<?php
// MARKER-PATCH-311 — promised_at: when a job is promised back to the customer.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            // UTC instant (like reminded_at). Nullable: retail sales and some
            // booking types have no promised-back moment.
            $table->timestamp('promised_at')->nullable()->after('appointment_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropColumn('promised_at');
        });
    }
};
