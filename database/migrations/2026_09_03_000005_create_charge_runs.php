<?php
// MARKER-BILLING-CHARGE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_charge_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // pending → charging → charged | failed | written_off | refunded
            $table->string('status', 16)->default('pending')->index();

            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('message_count')->default(0);

            // Sent to Stripe so a retry after a timeout cannot create a second
            // charge: Stripe returns the original intent instead.
            $table->string('idempotency_key', 64)->unique();

            $table->string('stripe_payment_intent_id', 64)->nullable()->index();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();

            // Per-transaction control, as asked for.
            $table->unsignedInteger('refunded_cents')->nullable();
            $table->string('resolution_reason', 255)->nullable();
            $table->string('resolved_by', 191)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('charged_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            // A row belongs to at most one run. This column IS the guard
            // against billing the same emails twice.
            $table->uuid('charge_run_id')->nullable()->after('is_free')->index();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('charge_threshold_cents')->nullable()->after('billing_email');
            $table->boolean('charging_enabled')->default(false)->after('charge_threshold_cents');
            $table->timestamp('campaigns_paused_at')->nullable()->after('charging_enabled');
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            // The master switch. Off means no tenant charges, whatever their
            // own setting says.
            $table->boolean('charging_enabled')->default(false)->after('email_free_monthly');
            $table->unsignedInteger('charge_threshold_default_cents')->default(2500)->after('charging_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_charge_runs');
        Schema::table('tenant_email_ledger', fn (Blueprint $t) => $t->dropColumn('charge_run_id'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['charge_threshold_cents', 'charging_enabled', 'campaigns_paused_at']));
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn(['charging_enabled', 'charge_threshold_default_cents']));
    }
};
