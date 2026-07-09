<?php
// MARKER-PATCH-610 — time clock: staff punches. clock_out_at nullable = on the clock.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_time_punches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->timestamp('clock_in_at');            // UTC instant
            $table->timestamp('clock_out_at')->nullable(); // UTC instant; null = open
            $table->string('source', 20)->default('page'); // page | lock_screen | manual
            $table->string('note', 500)->nullable();
            $table->uuid('created_by')->nullable();       // who recorded it (manager edits)
            $table->boolean('auto_closed')->default(false); // guardrail: closed by system
            $table->timestamps();

            $table->index(['tenant_id', 'tenant_user_id', 'clock_in_at']);
            $table->index(['tenant_id', 'clock_out_at']); // fast "who's on the clock"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_time_punches');
    }
};

