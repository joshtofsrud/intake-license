<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CONNECTED-POS-PUSH: Intake → Connected POS.
 *
 * tenant_pos_pushes  — every send (sale, refund, item create/update), one row per
 *                      thing sent, so a retry can never send it twice.
 * tenant_pos_items   — snapshot of the shared fields as both sides last agreed,
 *                      and any open conflict waiting for the shop to decide.
 * connections        — orders-out switch and the POS setup a sale needs
 *                      (register, user, payment type, tax), what the POS offers
 *                      for those, and the items-out rules.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('tenant_pos_pushes')) {
            Schema::create('tenant_pos_pushes', function (Blueprint $t) {
                $t->id();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('kind', 20);           // sale | refund | item_create | item_update
                $t->string('ref_id', 40);         // order id, refund sale id, inventory item id
                $t->string('label', 200)->nullable();
                $t->string('status', 12)->default('pending'); // pending | done | failed | skipped
                $t->string('external_id', 100)->nullable();
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->text('last_error')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'kind', 'ref_id'], 'tpp_ref');
                $t->index(['tenant_id', 'status', 'created_at'], 'tpp_status');
            });
        }

        Schema::table('tenant_pos_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_items', 'snapshot')) {
                $t->json('snapshot')->nullable();
                $t->json('conflict')->nullable();
            }
        });

        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_connections', 'orders_out')) {
                $t->boolean('orders_out')->default(false);
                $t->json('order_setup')->nullable();
                $t->json('setup_options')->nullable();
                $t->string('items_create', 12)->default('orders'); // orders | always
                $t->boolean('items_edits')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pos_pushes');
    }
};
