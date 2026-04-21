<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addon framework foundation.
 *
 * Tables:
 *   addons                      - master catalog of every addon/feature Intake sells
 *   tenant_addons               - join: which tenant has which addon, how they got it
 *   tenant_addon_suppressions   - staff-revoked access to plan-included features
 *   addon_audit_log             - every activate/deactivate/payment event
 *
 * Pitfall notes from prior sessions:
 *   - FK constraint names cap at 64 chars in MySQL. Short explicit names used throughout.
 *   - down() is destructive; drops all data. Fine for pre-launch; revisit post-launch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique();
            $t->string('name');
            $t->string('category', 32)->default('operations');
            $t->text('description')->nullable();
            $t->text('tooltip')->nullable();

            $t->unsignedInteger('price_cents')->default(0);
            $t->string('billing_cadence', 16)->default('monthly');
            $t->string('price_display_override', 64)->nullable();

            $t->json('included_in_plans')->nullable();

            $t->string('stripe_product_id', 64)->nullable();
            $t->string('stripe_price_id_monthly', 64)->nullable();
            $t->string('stripe_price_id_annual', 64)->nullable();
            $t->string('stripe_price_id_onetime', 64)->nullable();

            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->string('status', 16)->default('active');
            $t->boolean('is_self_serve')->default(true);
            $t->boolean('is_new')->default(false);

            $t->timestamps();

            $t->index(['status', 'sort_order']);
            $t->index('category');
        });

        Schema::create('tenant_addons', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('addon_code', 64);

            $t->string('source', 24)->default('self_serve');
            $t->string('status', 24)->default('active');

            $t->string('stripe_subscription_item_id', 64)->nullable();
            $t->string('stripe_price_id', 64)->nullable();

            $t->timestamp('activated_at')->nullable();
            $t->timestamp('canceling_at')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->timestamp('expired_at')->nullable();

            $t->json('metadata')->nullable();

            $t->timestamps();

            $t->foreign('tenant_id', 'ta_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();

            $t->foreign('addon_code', 'ta_addon_code_fk')
                ->references('code')->on('addons')->cascadeOnUpdate();

            $t->index(['tenant_id', 'status'], 'ta_tenant_status_idx');
            $t->index(['tenant_id', 'addon_code'], 'ta_tenant_code_idx');
            $t->index('current_period_end', 'ta_cpe_idx');
        });

        Schema::create('tenant_addon_suppressions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('addon_code', 64);
            $t->unsignedBigInteger('suppressed_by_user_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('suppressed_at')->useCurrent();
            $t->timestamp('lifted_at')->nullable();
            $t->timestamps();

            $t->foreign('tenant_id', 'tas_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();

            $t->foreign('addon_code', 'tas_addon_code_fk')
                ->references('code')->on('addons')->cascadeOnUpdate();

            $t->index(['tenant_id', 'addon_code', 'lifted_at'], 'tas_active_idx');
        });

        Schema::create('addon_audit_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('addon_code', 64);

            $t->string('action', 32);

            $t->string('actor_type', 16);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_label')->nullable();

            $t->text('reason')->nullable();
            $t->json('metadata')->nullable();

            $t->timestamp('created_at')->useCurrent();

            $t->foreign('tenant_id', 'aal_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();

            $t->index(['tenant_id', 'addon_code'], 'aal_tenant_code_idx');
            $t->index('created_at', 'aal_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_audit_log');
        Schema::dropIfExists('tenant_addon_suppressions');
        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('addons');
    }
};
