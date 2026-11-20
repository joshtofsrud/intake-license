<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CONNECTED-POS-CATALOG: the shop's Connected POS as a supply source.
 *
 * tenant_pos_items — every product the Connected POS has, linked to an Intake item.
 * tenant_pos_stock — its count per POS outlet, as last read.
 * Connection gains: outlet → Intake location map, what to do when an item leaves
 * the POS, sync on/off and live sync state.
 * Movement enum gains 'pos_sync' so a POS count lands in stock history honestly.
 * Scopes widen so Intake can later write stock and create items in the POS.
 */
return new class extends Migration {
    private const OLD_X = 'retailer:read outlets:read payment_types:read products:read inventory:read customers:read customers:write sales:read sales:write webhooks';
    private const NEW_X = 'retailer:read outlets:read payment_types:read products:read products:write inventory:read inventory:write customers:read customers:write sales:read sales:write webhooks';
    private const OLD_R = 'employee:inventory_read employee:customers employee:register employee:register_refund';
    private const NEW_R = 'employee:inventory employee:customers employee:register employee:register_refund';

    public function up(): void
    {
        if (! Schema::hasTable('tenant_pos_items')) {
            Schema::create('tenant_pos_items', function (Blueprint $t) {
                $t->id();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('adapter_code', 40);
                $t->string('external_id', 100);
                $t->string('parent_external_id', 100)->nullable();
                $t->uuid('inventory_item_id')->nullable();
                $t->string('name', 255)->nullable();
                $t->string('sku', 100)->nullable();
                $t->string('upc', 40)->nullable();
                $t->string('ean', 40)->nullable();
                $t->string('mpn', 100)->nullable();
                $t->string('brand', 150)->nullable();
                $t->integer('price_cents')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamp('removed_at')->nullable();
                $t->timestamp('synced_at')->nullable();
                $t->unique(['tenant_id', 'external_id'], 'tpi_tenant_ext');
                $t->index(['tenant_id', 'inventory_item_id'], 'tpi_tenant_item');
            });
        }

        if (! Schema::hasTable('tenant_pos_stock')) {
            Schema::create('tenant_pos_stock', function (Blueprint $t) {
                $t->id();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('external_item_id', 100);
                $t->string('external_location_id', 100);
                $t->integer('qty')->default(0);
                $t->timestamp('synced_at')->nullable();
                $t->unique(['tenant_id', 'external_item_id', 'external_location_id'], 'tps_unique');
            });
        }

        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_connections', 'location_map')) {
                $t->json('location_map')->nullable();
                $t->string('removal_mode', 20)->default('keep');
                $t->boolean('sync_enabled')->default(false);
                $t->json('sync_state')->nullable();
                $t->timestamp('last_synced_at')->nullable();
            }
        });

        // movement enum: append to what is really there (same method as the merge migration)
        $row = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_inventory_movements' AND COLUMN_NAME = 'movement_type'");
        if (! $row || ! preg_match_all("/'((?:[^']|'')*)'/", $row->t, $m)) {
            throw new RuntimeException('Could not read the movement_type enum definition.');
        }
        $values = array_map(fn ($v) => str_replace("''", "'", $v), $m[1]);
        if (! in_array('pos_sync', $values, true)) {
            $all = array_merge($values, ['pos_sync']);
            $list = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $all));
            DB::statement("ALTER TABLE tenant_inventory_movements MODIFY COLUMN movement_type ENUM({$list}) NOT NULL");
        }

        // widen scopes only where still the shipped default; an edited value is left alone
        DB::table('platform_pos_adapters')->where('code', 'lightspeed-x')->where('scopes', self::OLD_X)->update(['scopes' => self::NEW_X]);
        DB::table('platform_pos_adapters')->where('code', 'lightspeed-r')->where('scopes', self::OLD_R)->update(['scopes' => self::NEW_R]);
    }

    public function down(): void
    {
        // additive; the enum value and data are kept (a release may roll back without losing history)
        Schema::dropIfExists('tenant_pos_stock');
        Schema::dropIfExists('tenant_pos_items');
    }
};
