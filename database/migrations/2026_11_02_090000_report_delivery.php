<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REPORT-DELIVERY: emailed reports, alerts and dashboard pins. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_report_deliveries')) {
            Schema::create('tenant_report_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('report_id')->unique();
                $table->string('email_freq', 12)->default('never');     // never | daily | weekly | monthly | payperiod
                $table->unsignedTinyInteger('email_day')->default(1);    // weekday 1-7, or day of month 1-28
                $table->string('email_time', 5)->default('07:00');       // shop's local time
                $table->text('recipients')->nullable();                 // comma separated
                $table->boolean('attach_csv')->default(true);
                $table->boolean('skip_empty')->default(false);
                $table->timestamp('next_send_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->boolean('alert_on')->default(false);
                $table->string('alert_measure', 40)->nullable();
                $table->string('alert_op', 10)->default('below');       // below | above | change
                $table->decimal('alert_value', 14, 2)->nullable();
                $table->json('alert_state')->nullable();
                $table->date('alert_checked_on')->nullable();
                $table->timestamp('alert_last_at')->nullable();
                $table->timestamps();
                $table->index(['email_freq', 'next_send_at'], 'trd_due_idx');
                $table->index('alert_on', 'trd_alert_idx');
            });
        }

        if (!Schema::hasTable('tenant_report_pins')) {
            Schema::create('tenant_report_pins', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('report_id');
                $table->uuid('user_id');
                $table->string('show_as', 8)->default('table');        // table | chart | number
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['report_id', 'user_id'], 'trp_report_user_unique');
                $table->index(['tenant_id', 'user_id'], 'trp_tenant_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_report_pins');
        Schema::dropIfExists('tenant_report_deliveries');
    }
};
