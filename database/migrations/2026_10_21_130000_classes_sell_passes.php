<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes phase 1: passes are sold, and every credit movement is recorded.
 *
 *  - tenant_sale_items.type gains 'class_pass' (a pack or membership line).
 *  - pass products say which classes they cover (NULL = all) and whether
 *    they carry sales tax.
 *  - a pack or membership created by a sale points at that sale line, so
 *    a replayed commit can't create it twice and a refund can find it.
 *  - tenant_class_credit_entries: one row per credit in or out.
 *  - a customer may hold more than one membership (decision 3), so the
 *    one-active-membership key added in phase 0 goes. Its active_slot
 *    column stays until a later release (expand/contract).
 *
 * All additive or index-only. Re-runnable.
 *
 * MARKER-CLASSES-P1
 */
return new class extends Migration
{
    public function up(): void
    {
        $col = DB::selectOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_sale_items' AND COLUMN_NAME = 'type'");
        if ($col && ! str_contains($col->t, "'class_pass'")) {
            DB::statement("ALTER TABLE tenant_sale_items MODIFY `type` ENUM('service','product','open_item','gift_card','class_pass') NOT NULL");
        }

        foreach (['tenant_class_pack_products', 'tenant_class_membership_products'] as $t) {
            if (! Schema::hasColumn($t, 'covers')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->json('covers')->nullable()->after('description');
                });
            }
            if (! Schema::hasColumn($t, 'is_taxable')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->boolean('is_taxable')->default(false)->after('price_cents');
                });
            }
        }

        foreach (['tenant_customer_packs' => 'tcp_sale_item_unique', 'tenant_customer_memberships' => 'tcm_sale_item_unique'] as $t => $idx) {
            if (! Schema::hasColumn($t, 'sale_item_id')) {
                Schema::table($t, function (Blueprint $b) use ($idx) {
                    $b->char('sale_item_id', 36)->nullable()->after('product_id');
                    $b->unique('sale_item_id', $idx);
                });
            }
        }

        if (! Schema::hasTable('tenant_class_credit_entries')) {
            Schema::create('tenant_class_credit_entries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
                $t->char('pack_id', 36)->nullable();
                $t->char('membership_id', 36)->nullable();
                $t->char('registration_id', 36)->nullable();
                $t->char('sale_id', 36)->nullable();
                $t->smallInteger('delta');
                $t->string('reason', 24);
                $t->string('note', 255)->nullable();
                $t->char('actor_user_id', 36)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['tenant_id', 'customer_id', 'created_at'], 'tcce_customer_time');
                $t->index('pack_id', 'tcce_pack');
                $t->index('membership_id', 'tcce_membership');
            });
        }

        if (DB::select("SHOW INDEX FROM tenant_customer_memberships WHERE Key_name = 'tcm_one_active_membership'")) {
            Schema::table('tenant_customer_memberships', fn (Blueprint $b) => $b->dropUnique('tcm_one_active_membership'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_credit_entries');
    }
};

