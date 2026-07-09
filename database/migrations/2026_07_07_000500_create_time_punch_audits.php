<?php
// MARKER-PATCH-614 — time punch audit log. Every create/edit/auto-close writes
// a row here so the team timesheet has a permanent, immutable trail (the
// on-punch edited_* fields only hold the LAST edit; payroll truth needs all).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_time_punch_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('punch_id')->nullable()->index(); // nullable: survives punch deletion
            $table->uuid('subject_user_id')->nullable();   // whose punch
            $table->uuid('actor_id')->nullable();          // who made the change (null = system)
            $table->string('action', 24);                  // created | edited | auto_closed | deleted
            $table->string('detail', 800)->nullable();     // human summary incl. reason
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_time_punch_audits');
    }
};

