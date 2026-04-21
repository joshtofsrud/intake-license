<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tenant-level settings (1:1 with tenant)
        Schema::create('tenant_waitlist_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $t->boolean('enabled')->default(false);
            $t->enum('similar_match_rule', ['exact_only', 'by_duration', 'by_category', 'by_tenant_mapping'])
                ->default('exact_only');
            $t->boolean('exclude_first_time_customers')->default(false);
            $t->boolean('include_cancellations')->default(true);
            $t->boolean('include_new_openings')->default(false); // deferred to v2
            $t->boolean('include_manual_offers')->default(true);
            $t->boolean('notify_sms')->default(true);
            $t->boolean('notify_email')->default(true);
            $t->unsignedSmallInteger('max_entries_per_customer')->nullable();
            $t->text('offer_copy_override')->nullable();
            $t->timestamps();
        });

        // Customer waitlist entries
        Schema::create('tenant_waitlist_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
            $t->foreignUuid('service_item_id')->constrained('tenant_service_items')->cascadeOnDelete();
            $t->json('addon_ids')->nullable();
            $t->date('date_range_start');
            $t->date('date_range_end');
            $t->json('preferred_days')->nullable();
            $t->time('preferred_time_start')->nullable();
            $t->time('preferred_time_end')->nullable();
            $t->text('notes')->nullable();
            $t->enum('status', ['active', 'fulfilled', 'expired', 'cancelled_by_customer', 'cancelled_by_tenant'])
                ->default('active');
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'date_range_end'], 'wl_entry_lookup_idx');
            $t->index(['tenant_id', 'customer_id', 'status'], 'wl_entry_customer_idx');
            $t->index(['tenant_id', 'service_item_id', 'status'], 'wl_entry_service_idx');
        });

        // Offers sent when slots open
        Schema::create('tenant_waitlist_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('waitlist_entry_id')->constrained('tenant_waitlist_entries')->cascadeOnDelete();
            $t->string('offer_token', 48)->unique();
            $t->dateTime('slot_datetime');
            $t->enum('slot_source', ['cancellation', 'new_hours', 'manual_tenant_offer']);
            $t->foreignUuid('triggering_appointment_id')->nullable()->constrained('tenant_appointments')->nullOnDelete();
            $t->dateTime('notified_at')->nullable();
            $t->dateTime('viewed_at')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->foreignUuid('resulting_appointment_id')->nullable()->constrained('tenant_appointments')->nullOnDelete();
            $t->enum('status', ['pending', 'viewed', 'accepted', 'slot_taken', 'declined', 'expired'])
                ->default('pending');
            $t->dateTime('offer_expires_at');
            $t->boolean('sms_sent')->default(false);
            $t->boolean('email_sent')->default(false);
            $t->text('sms_error')->nullable();
            $t->text('email_error')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'waitlist_entry_id'], 'wl_offer_entry_idx');
            $t->index(['tenant_id', 'slot_datetime', 'status'], 'wl_offer_slot_idx');
        });

        // Similar-service mapping (for by_tenant_mapping rule)
        Schema::create('tenant_waitlist_similar_map', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('service_item_id')->constrained('tenant_service_items')->cascadeOnDelete();
            $t->foreignUuid('substitutable_service_item_id');
            $t->foreign('substitutable_service_item_id', 'wl_sm_sub_svc_fk')
                ->references('id')->on('tenant_service_items')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'service_item_id', 'substitutable_service_item_id'], 'wl_similar_unique');
        });

        // Feature gate flag on tenants (a la carte for basic plans)
        if (!Schema::hasColumn('tenants', 'has_waitlist_addon')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->boolean('has_waitlist_addon')->default(false)->after('plan_tier');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'has_waitlist_addon')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->dropColumn('has_waitlist_addon');
            });
        }
        Schema::dropIfExists('tenant_waitlist_similar_map');
        Schema::dropIfExists('tenant_waitlist_offers');
        Schema::dropIfExists('tenant_waitlist_entries');
        Schema::dropIfExists('tenant_waitlist_settings');
    }
};
