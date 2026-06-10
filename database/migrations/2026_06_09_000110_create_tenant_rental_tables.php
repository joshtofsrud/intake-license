<?php
// MARKER-PATCH-217 — rentals schema, full build-plan section 2.1.
//
// Conventions follow tenant_sales / tenant_appointments:
//   - uuid PKs, foreignUuid tenant scoping, restrict on money-bearing FKs
//   - string status columns (not enums) so later states don't need ALTERs
//   - snapshot-on-write columns on lines and agreements
//   - all *_at datetimes are UTC instants (display via tlocal()); rentals are
//     real instants, unlike appointment wall-clock dates
//
// Money rail: tenant_rental_payments mirrors tenant_appointment_payments
// column-for-column so the revenue report and reconciliation extend with one
// UNION arm each. Deposit AUTHORIZATIONS never write here — only charges,
// captures, refunds.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_rental_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->string('name');
            $t->unsignedInteger('hourly_rate_cents')->nullable();
            $t->unsignedInteger('daily_rate_cents')->nullable();
            $t->unsignedInteger('weekend_rate_cents')->nullable();
            $t->unsignedInteger('deposit_cents')->default(0);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'archived_at'], 'trc_tenant_active');
        });

        Schema::create('tenant_rental_addons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->string('name');
            $t->string('pricing_mode', 16)->default('flat'); // flat | per_day
            $t->unsignedInteger('price_cents')->default(0);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'archived_at'], 'tra_tenant_active');
        });

        Schema::create('tenant_rental_condition_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->string('name');
            $t->json('items'); // [{key, label}] checked at check-out/check-in
            $t->timestamps();
            $t->index('tenant_id', 'trct_tenant');
        });

        Schema::create('tenant_rental_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('location_id')->nullable()->constrained('tenant_locations')->nullOnDelete();
            $t->foreignUuid('category_id')->constrained('tenant_rental_categories')->onDelete('restrict');
            $t->string('name');                       // "Trek Roscoe 8"
            $t->string('identifier', 60)->nullable(); // tag "#BH-088"
            $t->string('size', 40)->nullable();
            // available | maintenance | retired. "out" is DERIVED from the
            // active rental, never persisted — no stale-state class of bug.
            $t->string('status', 16)->default('available');
            $t->boolean('available_for_rent')->default(true); // desk + public
            $t->boolean('online_booking')->default(true);     // public self-reserve
            $t->unsignedSmallInteger('buffer_minutes')->default(0);
            $t->foreignUuid('condition_template_id')->nullable()
              ->constrained('tenant_rental_condition_templates')->nullOnDelete();
            // Per-unit rate overrides; null = inherit category rate card.
            $t->unsignedInteger('hourly_rate_cents_override')->nullable();
            $t->unsignedInteger('daily_rate_cents_override')->nullable();
            $t->unsignedInteger('weekend_rate_cents_override')->nullable();
            $t->unsignedInteger('deposit_cents_override')->nullable();
            $t->date('acquired_at')->nullable();
            $t->json('metadata')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'category_id'], 'tru_tenant_category');
            $t->index(['tenant_id', 'status'], 'tru_tenant_status');
        });

        Schema::create('tenant_rental_agreement_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->unsignedInteger('version');
            $t->string('title');
            $t->longText('body');
            $t->foreignUuid('created_by_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'version'], 'trat_tenant_version');
        });

        Schema::create('tenant_rentals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('location_id')->nullable()->constrained('tenant_locations')->nullOnDelete();
            // RAIL 3 (customer history): indexed customer link — the timeline
            // source query reads this directly.
            $t->foreignUuid('customer_id')->constrained('tenant_customers')->onDelete('restrict');
            $t->string('rental_number', 24);
            $t->string('status', 16)->default('reserved'); // reserved | out | returned | cancelled
            $t->string('source', 16)->default('desk');     // desk | online
            $t->dateTime('starts_at');
            $t->dateTime('due_at');
            $t->dateTime('original_due_at')->nullable();   // set when an extension moves due_at
            $t->dateTime('returned_at')->nullable();
            $t->unsignedInteger('subtotal_cents')->default(0);
            $t->unsignedInteger('tax_cents')->default(0);
            $t->unsignedInteger('total_cents')->default(0);
            $t->unsignedInteger('paid_cents')->default(0); // denormalized ledger sum
            $t->unsignedInteger('deposit_hold_cents')->default(0);
            // none | authorized | released | captured | partially_captured
            $t->string('deposit_status', 24)->default('none');
            $t->string('stripe_deposit_intent_id', 64)->nullable();
            $t->unsignedInteger('agreement_template_version')->nullable();
            $t->dateTime('agreement_signed_at')->nullable();
            $t->string('agreement_method', 20)->nullable(); // desk | sms_link
            $t->string('agreement_pdf_path')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status'], 'trn_tenant_status');
            $t->index(['tenant_id', 'due_at'], 'trn_tenant_due');
            $t->index(['tenant_id', 'starts_at'], 'trn_tenant_starts');
            $t->index(['tenant_id', 'rental_number'], 'trn_tenant_number');
            $t->index('customer_id', 'trn_customer');
        });

        Schema::create('tenant_rental_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rental_id')->constrained('tenant_rentals')->onDelete('cascade');
            $t->string('kind', 12); // unit | addon
            $t->foreignUuid('unit_id')->nullable()->constrained('tenant_rental_units')->nullOnDelete();
            $t->foreignUuid('addon_id')->nullable()->constrained('tenant_rental_addons')->nullOnDelete();
            // Snapshot-on-write: renames/repricing never rewrite history.
            $t->string('name_snapshot');
            $t->string('rate_mode_snapshot', 16); // hourly | daily | weekend | flat | per_day
            $t->unsignedInteger('rate_cents_snapshot')->default(0);
            $t->unsignedInteger('quantity')->default(1);
            $t->unsignedInteger('duration_units')->nullable(); // hours or days per rate mode
            $t->unsignedInteger('line_total_cents')->default(0);
            $t->unsignedSmallInteger('sort_order')->default(10);
            $t->timestamps();
            $t->index('rental_id', 'trl_rental');
            $t->index('unit_id', 'trl_unit');
        });

        Schema::create('tenant_rental_condition_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rental_id')->constrained('tenant_rentals')->onDelete('cascade');
            $t->foreignUuid('unit_id')->nullable()->constrained('tenant_rental_units')->nullOnDelete();
            $t->string('phase', 12); // check_out | check_in
            $t->json('results');     // template items + pass/flag per item
            $t->boolean('flagged')->default(false); // a flagged check_in authorizes deposit capture
            $t->text('notes')->nullable();
            $t->json('photos')->nullable();
            $t->foreignUuid('performed_by_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->dateTime('performed_at')->nullable();
            $t->timestamps();
            $t->index('rental_id', 'trcc_rental');
        });

        // RAIL 2 (ledger). Column-for-column mirror of
        // tenant_appointment_payments. amount_cents is SIGNED — refunds are
        // negative rows, matching the sale-payments convention.
        Schema::create('tenant_rental_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('rental_id')->constrained('tenant_rentals')->onDelete('restrict');
            $t->integer('amount_cents');
            $t->string('kind', 24);    // charge | refund | deposit_capture
            $t->string('source', 16);  // desk | online | extension
            $t->string('method', 24)->nullable();
            $t->string('external_reference')->nullable(); // Stripe PI / charge id
            $t->foreignUuid('recorded_by_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'recorded_at'], 'trp_tenant_recorded');
            $t->index('rental_id', 'trp_rental');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_rental_payments');
        Schema::dropIfExists('tenant_rental_condition_checks');
        Schema::dropIfExists('tenant_rental_lines');
        Schema::dropIfExists('tenant_rentals');
        Schema::dropIfExists('tenant_rental_agreement_templates');
        Schema::dropIfExists('tenant_rental_units');
        Schema::dropIfExists('tenant_rental_condition_templates');
        Schema::dropIfExists('tenant_rental_addons');
        Schema::dropIfExists('tenant_rental_categories');
    }
};
