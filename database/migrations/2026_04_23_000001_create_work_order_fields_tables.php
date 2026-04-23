<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Field definitions — one row per field per tenant
        Schema::create('tenant_work_order_fields', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('label', 100);
            $t->string('field_type', 20);          // text|textarea|number|select
            $t->json('options')->nullable();        // for select: ["Red","Blue",...]
            $t->string('help_text', 255)->nullable();
            $t->boolean('is_required')->default(false);
            $t->boolean('is_identifier')->default(false); // at most one per tenant; see app-level check
            $t->boolean('is_customer_visible')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'sort_order'], 'wof_tenant_order_idx');
        });

        // Responses — one row per filled-in value
        Schema::create('tenant_appointment_work_order_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $t->foreignUuid('field_id')->nullable()->constrained('tenant_work_order_fields')->nullOnDelete();
            $t->string('field_label_snapshot', 100); // survives field deletion
            $t->text('response_value')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'appointment_id'], 'wor_appt_idx');
            $t->index(['tenant_id', 'field_id'], 'wor_field_idx');
        });

        // Promoted identifier on appointments — fast cross-appointment lookup
        if (!Schema::hasColumn('tenant_appointments', 'identifier')) {
            Schema::table('tenant_appointments', function (Blueprint $t) {
                $t->string('identifier', 120)->nullable()->after('tracking_number');
                $t->string('identifier_label', 60)->nullable()->after('identifier');
                $t->index(['tenant_id', 'identifier'], 'appts_identifier_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_appointments', 'identifier')) {
            Schema::table('tenant_appointments', function (Blueprint $t) {
                $t->dropIndex('appts_identifier_idx');
                $t->dropColumn(['identifier', 'identifier_label']);
            });
        }
        Schema::dropIfExists('tenant_appointment_work_order_responses');
        Schema::dropIfExists('tenant_work_order_fields');
    }
};
