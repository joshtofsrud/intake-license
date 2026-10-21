<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes phase 2a: memberships are billed.
 *
 *  - status gains 'past_due' (renewal not paid yet; still books for 7 days).
 *  - billing_mode: card (shop charges the saved card), desk (pays at the
 *    desk), comp (given by staff, renews free). Existing rows: comp, except
 *    memberships already sold at the register (phase 1), which are desk.
 *  - saved card, retry state, renewal price, cancel-at-period-end, pause.
 *
 * Additive only. Re-runnable. MARKER-CLASSES-P2
 */
return new class extends Migration
{
    public function up(): void
    {
        $col = DB::selectOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_customer_memberships' AND COLUMN_NAME = 'status'");
        if ($col && ! str_contains($col->t, "'past_due'")) {
            DB::statement("ALTER TABLE tenant_customer_memberships MODIFY `status` ENUM('active','past_due','cancelled','expired','paused') NOT NULL DEFAULT 'active'");
        }

        $add = [
            'billing_mode'             => fn (Blueprint $t) => $t->string('billing_mode', 8)->default('comp')->after('status'),
            'renewal_price_cents'      => fn (Blueprint $t) => $t->unsignedInteger('renewal_price_cents')->nullable()->after('billing_mode'),
            'stripe_payment_method_id' => fn (Blueprint $t) => $t->string('stripe_payment_method_id', 64)->nullable()->after('stripe_subscription_id'),
            'card_brand'               => fn (Blueprint $t) => $t->string('card_brand', 20)->nullable()->after('stripe_payment_method_id'),
            'card_last4'               => fn (Blueprint $t) => $t->char('card_last4', 4)->nullable()->after('card_brand'),
            'failed_attempts'          => fn (Blueprint $t) => $t->unsignedTinyInteger('failed_attempts')->default(0)->after('card_last4'),
            'next_retry_at'            => fn (Blueprint $t) => $t->dateTime('next_retry_at')->nullable()->after('failed_attempts'),
            'last_charge_error'        => fn (Blueprint $t) => $t->string('last_charge_error', 255)->nullable()->after('next_retry_at'),
            'cancel_at_period_end'     => fn (Blueprint $t) => $t->boolean('cancel_at_period_end')->default(false)->after('last_charge_error'),
            'pause_until'              => fn (Blueprint $t) => $t->date('pause_until')->nullable()->after('cancel_at_period_end'),
        ];
        foreach ($add as $name => $def) {
            if (! Schema::hasColumn('tenant_customer_memberships', $name)) {
                Schema::table('tenant_customer_memberships', $def);
                if ($name === 'billing_mode') {
                    // Sold at the register in phase 1 (no saved card yet): pays at the desk.
                    DB::statement("UPDATE tenant_customer_memberships SET billing_mode = 'desk' WHERE sale_item_id IS NOT NULL");
                }
            }
        }
        if (! DB::select("SHOW INDEX FROM tenant_customer_memberships WHERE Key_name = 'tcm_billing_due'")) {
            Schema::table('tenant_customer_memberships', fn (Blueprint $t) => $t->index(['status', 'current_period_end'], 'tcm_billing_due'));
        }

        // Renewal price for desk memberships sold in phase 1: the product's price.
        DB::statement("UPDATE tenant_customer_memberships m JOIN tenant_class_membership_products p ON p.id = m.product_id
            SET m.renewal_price_cents = p.price_cents WHERE m.renewal_price_cents IS NULL AND m.billing_mode <> 'comp'");
    }

    public function down(): void
    {
    }
};

