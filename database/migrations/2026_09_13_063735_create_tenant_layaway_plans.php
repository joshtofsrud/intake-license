<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MARKER-LAYAWAY — one plan per layaway sale, plus the capability backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_layaway_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->unique()->constrained('tenant_sales')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $table->foreignUuid('location_id')->constrained('tenant_locations')->cascadeOnDelete();

            // active   — owing, goods held and/or on order
            // ready    — paid in full, everything in hand, not yet collected
            // completed — handed over; the sale is now a sale
            // cancelled
            $table->string('status', 12)->default('active');

            $table->unsignedSmallInteger('term_days');
            $table->string('frequency', 12);          // weekly | biweekly | monthly | none
            $table->unsignedSmallInteger('grace_days');
            $table->json('policy');                    // snapshot of settings at open
            $table->date('collect_by')->nullable();
            $table->date('next_due_on')->nullable();
            $table->unsignedInteger('scheduled_amount_cents')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->unsignedInteger('restocking_fee_cents')->default(0);
            $table->unsignedInteger('refund_cents')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'next_due_on'], 'tlp_tenant_status_due_idx');
            $table->index(['tenant_id', 'customer_id', 'status'], 'tlp_tenant_customer_idx');
        });

        // Capabilities backfilled onto every role that carries an explicit
        // list, same reasoning as register.line_price: a role saved from the
        // Roles page has an exhaustive list, and a new key lands as denied.
        foreach (DB::table('tenant_roles')->whereNotNull('capabilities')->get(['id', 'capabilities']) as $role) {
            $caps = json_decode((string) $role->capabilities, true);
            if (! is_array($caps)) {
                continue;
            }
            $add = ['register.layaway.create', 'register.layaway.cancel'];
            $new = array_values(array_unique(array_merge($caps, $add)));
            if ($new !== $caps) {
                DB::table('tenant_roles')->where('id', $role->id)->update(['capabilities' => json_encode($new)]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('tenant_layaway_plans')->exists()) {
            throw new RuntimeException('Layaway plans exist; refusing to drop them.');
        }
        Schema::dropIfExists('tenant_layaway_plans');
    }
};
