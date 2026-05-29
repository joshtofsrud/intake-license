<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-PATCH-175 — Payment ledger for SALES.
 *
 * Part of the money-model unification: the sale becomes the single money
 * object, and every dollar in/out is a row here. Mirrors the proven
 * tenant_appointment_payments shape.
 *
 * Additive only. Nothing reads this yet (patch-176+ repoints writers/readers).
 *
 * Backfill: every paid, non-refund tenant_sales row gets one payment row equal
 * to its total_cents, dated by paid_at (fallback created_at), method from the
 * sale's payment_method. This makes "payments received" reconcile to existing
 * paid-sale totals on day one. Refund sales (refund_of_sale_id set) get a
 * negative 'refund' row.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tenant_sale_payments')) {
            return; // idempotent
        }

        Schema::create('tenant_sale_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->foreignUuid('tenant_id')
              ->constrained('tenants')
              ->onDelete('restrict');

            $t->foreignUuid('sale_id')
              ->constrained('tenant_sales')
              ->cascadeOnDelete();

            // Signed: positive = money in, negative = refund out.
            $t->integer('amount_cents');

            //   deposit         — early prepayment before the job is itemized
            //   balance         — payment settling the remaining bill
            //   payment         — a generic payment (walk-in retail paid in full)
            //   refund          — money returned (negative)
            //   overage_refund  — prepaid more than total; returning the diff (negative)
            $t->enum('kind', ['deposit', 'balance', 'payment', 'refund', 'overage_refund']);

            //   register             — closed/charged in the register
            //   booking_flow         — public-site customer checkout (Stripe Connect, later)
            //   manual_entry         — staff recorded it by hand
            //   stripe_webhook       — async Stripe event
            //   direct_payment_link  — send-payment-link (Checkout Session)
            $t->enum('source', ['register', 'booking_flow', 'manual_entry', 'stripe_webhook', 'direct_payment_link']);

            // cash | card_terminal | check | store_credit | mark_paid | stripe | paypal | other
            $t->string('method', 32);

            // Refund chain: refund row -> the payment it reverses.
            $t->uuid('reference_payment_id')->nullable();

            // Gateway reference (Stripe charge/intent id, check number, etc).
            $t->string('external_reference', 191)->nullable();

            $t->foreignUuid('recorded_by_user_id')
              ->nullable()
              ->constrained('tenant_users')
              ->onDelete('set null');

            $t->timestamp('recorded_at')->useCurrent();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'sale_id'], 'tsp_tenant_sale_idx');
            $t->index(['sale_id', 'kind'], 'tsp_sale_kind_idx');
            $t->index(['tenant_id', 'recorded_at'], 'tsp_tenant_recorded_idx');
            $t->index('reference_payment_id', 'tsp_reference_idx');
        });

        // Self-referential FK for the refund chain (table must exist first).
        Schema::table('tenant_sale_payments', function (Blueprint $t) {
            $t->foreign('reference_payment_id', 'tsp_reference_fk')
              ->references('id')->on('tenant_sale_payments')
              ->onDelete('set null');
        });

        // ---- Backfill from existing paid sales ----
        // Inbound: paid, non-refund sales -> one 'payment' row at total_cents.
        DB::table('tenant_sales')
            ->where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                $batch = [];
                foreach ($rows as $r) {
                    $when = $r->paid_at ?? $r->created_at ?? now();
                    $batch[] = [
                        'id'                   => (string) Str::uuid(),
                        'tenant_id'            => $r->tenant_id,
                        'sale_id'              => $r->id,
                        'amount_cents'         => (int) $r->total_cents,
                        'kind'                 => $r->appointment_id ? 'balance' : 'payment',
                        'source'               => 'register',
                        'method'               => $r->payment_method ?: 'other',
                        'reference_payment_id' => null,
                        'external_reference'   => $r->payment_reference ?? null,
                        'recorded_by_user_id'  => null,
                        'recorded_at'          => $when,
                        'notes'                => 'patch-175 backfill from paid sale ' . $r->sale_number,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }
                if ($batch) DB::table('tenant_sale_payments')->insert($batch);
            });

        // Outbound: refund sales -> one negative 'refund' row.
        DB::table('tenant_sales')
            ->whereNotNull('refund_of_sale_id')
            ->where('payment_status', 'paid')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                $batch = [];
                foreach ($rows as $r) {
                    $when = $r->paid_at ?? $r->created_at ?? now();
                    // total_cents is stored positive; refund is money out.
                    $batch[] = [
                        'id'                   => (string) Str::uuid(),
                        'tenant_id'            => $r->tenant_id,
                        'sale_id'              => $r->id,
                        'amount_cents'         => -1 * abs((int) $r->total_cents),
                        'kind'                 => 'refund',
                        'source'               => 'register',
                        'method'               => $r->payment_method ?: 'other',
                        'reference_payment_id' => null,
                        'external_reference'   => $r->payment_reference ?? null,
                        'recorded_by_user_id'  => null,
                        'recorded_at'          => $when,
                        'notes'                => 'patch-175 backfill from refund sale ' . $r->sale_number,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }
                if ($batch) DB::table('tenant_sale_payments')->insert($batch);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sale_payments');
    }
};
