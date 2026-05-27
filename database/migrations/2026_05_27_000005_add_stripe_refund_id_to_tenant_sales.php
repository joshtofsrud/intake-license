<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-171 — Direct Payments Session 2C.
 *
 * Stores the Stripe Refund ID (re_xxx) on a refund-direction sale row
 * after a successful refunds.create call. Used for:
 *   - Display on sale detail (link out to Stripe)
 *   - charge.refunded webhook idempotency (don\'t double-record)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->string('stripe_refund_id', 64)->nullable()->index('sale_rfd_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropIndex('sale_rfd_idx');
            $t->dropColumn('stripe_refund_id');
        });
    }
};
