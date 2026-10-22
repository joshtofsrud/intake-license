<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes phase 2b: selling online.
 *
 *  - pass products: sell_online (listed on /classes/passes), is_intro on
 *    packs (one per customer, shown only to people who never had one).
 *  - registrations: payment_method gains 'online' (paid by card online).
 *  - tenant_class_online_checkouts: one row per online payment started, so
 *    the browser return and the Stripe webhook settle it exactly once.
 *  - tenant_sales.rang_up_by_user_id may be empty: a sale made online has no
 *    staff member. Online store orders already write it empty
 *    (OrderService::finalize), which the original NOT NULL column refuses.
 *
 * Additive only. Re-runnable. MARKER-CLASSES-P3
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenant_class_pack_products', 'tenant_class_membership_products'] as $table) {
            if (! Schema::hasColumn($table, 'sell_online')) {
                Schema::table($table, fn (Blueprint $t) => $t->boolean('sell_online')->default(false)->after('is_active'));
            }
        }
        if (! Schema::hasColumn('tenant_class_pack_products', 'is_intro')) {
            Schema::table('tenant_class_pack_products', fn (Blueprint $t) => $t->boolean('is_intro')->default(false)->after('sell_online'));
        }

        $col = DB::selectOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_class_registrations' AND COLUMN_NAME = 'payment_method'");
        if ($col && ! str_contains($col->t, "'online'")) {
            DB::statement("ALTER TABLE tenant_class_registrations MODIFY `payment_method` ENUM('membership','pack','per_class','cash','online') NOT NULL");
        }

        $staff = DB::selectOne("SELECT IS_NULLABLE n, COLUMN_TYPE t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_sales' AND COLUMN_NAME = 'rang_up_by_user_id'");
        if ($staff && $staff->n === 'NO') {
            DB::statement("ALTER TABLE tenant_sales MODIFY `rang_up_by_user_id` {$staff->t} NULL");
        }

        if (! Schema::hasTable('tenant_class_online_checkouts')) {
            Schema::create('tenant_class_online_checkouts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->uuid('customer_id')->nullable()->index();
                $t->string('kind', 12);                    // pack | membership | dropin
                $t->uuid('product_id')->nullable();
                $t->uuid('session_id')->nullable();        // the class to book into, if any
                $t->unsignedInteger('amount_cents');
                $t->unsignedInteger('tax_cents')->default(0);
                $t->string('stripe_payment_intent_id', 64)->nullable()->unique();
                $t->string('status', 12)->default('pending'); // pending | paid | failed
                $t->uuid('sale_id')->nullable();
                $t->uuid('registration_id')->nullable();
                $t->uuid('pass_id')->nullable();           // the pack or membership it made
                $t->string('note', 255)->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
