<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment ledger for appointments.
 *
 * Every dollar that flows toward (or away from) an appointment becomes a row
 * in this table. The appointment's paid_cents column stays as a denormalized
 * cache of SUM(amount_cents) for fast list queries, but the ledger is the
 * source of truth.
 *
 * Why a ledger and not just paid_cents:
 *   - Multiple payments per appointment (deposit + balance, split tender)
 *   - Refund-method awareness — Stripe deposits refund via Stripe API,
 *     cash refunds from drawer. We need to know the original method.
 *   - Audit trail — staff/owner can see who took which payment when.
 *   - Reconciliation against register cash drawer.
 *
 * Backfill: any existing tenant_appointments.paid_cents > 0 gets a single
 * 'manual_entry' row so we don't lose history.
 *
 * NOTE on Stripe Connect: the schema includes 'stripe_webhook' as a source
 * value and 'stripe' as a method, but the writer for those is wired later
 * when Stripe Connect lands. For tonight, only manual_entry + register_sale
 * sources are used in code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_appointment_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->foreignUuid('tenant_id')
              ->constrained('tenants')
              ->onDelete('restrict');

            $t->foreignUuid('appointment_id')
              ->constrained('tenant_appointments')
              ->cascadeOnDelete();

            // Signed amount: positive = inbound (customer paying), negative
            // = outbound (refund or overage refund). The ledger sums to the
            // net paid against the appointment.
            $t->integer('amount_cents');

            // Why this row exists.
            //   deposit          — pre-service prepayment (booking flow or counter)
            //   balance          — final payment to settle the bill at completion
            //   refund           — money returned to customer (negative amount)
            //   overage_refund   — prepaid more than final total; returning the diff
            $t->enum('kind', [
                'deposit', 'balance', 'refund', 'overage_refund',
            ]);

            // Where the writer was triggered from.
            //   booking_flow     — public-site customer Stripe checkout (Phase 1.5)
            //   register_sale    — closed register sale created the row
            //   manual_entry     — staff clicked "Record deposit" → register
            //   stripe_webhook   — async Stripe event landed (Phase 1.5)
            $t->enum('source', [
                'booking_flow', 'register_sale', 'manual_entry', 'stripe_webhook',
            ]);

            // How the customer actually paid. Mirrors tenant_sales.payment_method
            // so register-sourced rows can copy the value cleanly.
            //   cash | card_terminal | check | store_credit | mark_paid |
            //   stripe | paypal | other
            $t->string('method', 32);

            // When source = 'register_sale' or 'manual_entry', this is the
            // sale that produced the row. Lets refund flow find the original
            // sale to refund against.
            $t->foreignUuid('register_sale_id')
              ->nullable()
              ->constrained('tenant_sales')
              ->onDelete('set null');

            // For refund kind, points to the row being refunded. Negative
            // amount + reference_payment_id = full audit chain.
            $t->uuid('reference_payment_id')->nullable();

            // Original gateway reference (Stripe charge ID, PayPal order ID,
            // check number, etc). Carried over from sale.payment_reference
            // when applicable.
            $t->string('external_reference', 191)->nullable();

            // Who recorded this in the system. Null for booking_flow + webhook.
            $t->foreignUuid('recorded_by_user_id')
              ->nullable()
              ->constrained('tenant_users')
              ->onDelete('set null');

            $t->timestamp('recorded_at')->useCurrent();

            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'appointment_id'], 'tap_tenant_appt_idx');
            $t->index(['appointment_id', 'kind'], 'tap_appt_kind_idx');
            $t->index('register_sale_id', 'tap_register_sale_idx');
            $t->index(['tenant_id', 'recorded_at'], 'tap_tenant_recorded_idx');
        });

        // Self-referential FK for refund chain. Done as a second statement
        // because the table needs to exist before we can reference it.
        Schema::table('tenant_appointment_payments', function (Blueprint $t) {
            $t->foreign('reference_payment_id', 'tap_reference_fk')
              ->references('id')->on('tenant_appointment_payments')
              ->onDelete('set null');
        });

        // Backfill: preserve any existing paid_cents data as a manual_entry row
        // so the ledger SUM() matches the cached column for every appointment.
        DB::table('tenant_appointments')
            ->where('paid_cents', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                $now = now();
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = [
                        'id'                  => (string) \Illuminate\Support\Str::uuid(),
                        'tenant_id'           => $row->tenant_id,
                        'appointment_id'      => $row->id,
                        'amount_cents'        => $row->paid_cents,
                        'kind'                => 'deposit',
                        'source'              => 'manual_entry',
                        'method'              => 'other',
                        'register_sale_id'    => null,
                        'reference_payment_id'=> null,
                        'external_reference'  => null,
                        'recorded_by_user_id' => null,
                        'recorded_at'         => $now,
                        'notes'               => 'Migrated from legacy paid_cents column',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }
                if ($batch) {
                    DB::table('tenant_appointment_payments')->insert($batch);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_payments', function (Blueprint $t) {
            $t->dropForeign('tap_reference_fk');
        });
        Schema::dropIfExists('tenant_appointment_payments');
    }
};
