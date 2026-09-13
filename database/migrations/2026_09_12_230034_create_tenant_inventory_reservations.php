<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-RESERVE — stock held for someone.
 *
 * Both enum changes read the live column definition and APPEND, the pattern
 * that fixed the merge migration: restating an enum from any one migration
 * file drops whatever a later file added.
 */
return new class extends Migration
{
    private function enumValues(string $table, string $column): array
    {
        $row = DB::selectOne("
            SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ", [$table, $column]);

        if (! $row || ! preg_match_all("/'((?:[^']|'')*)'/", $row->t, $m)) {
            throw new RuntimeException("Could not read the {$table}.{$column} enum.");
        }

        return array_map(fn ($v) => str_replace("''", "'", $v), $m[1]);
    }

    private function appendEnum(string $table, string $column, array $add, string $nullability): void
    {
        $have   = $this->enumValues($table, $column);
        $adding = array_values(array_diff($add, $have));

        if (! $adding) {
            return;
        }

        $list = implode(',', array_map(
            fn ($v) => "'" . str_replace("'", "''", $v) . "'",
            array_merge($have, $adding)
        ));

        DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} ENUM({$list}) {$nullability}");
    }

    public function up(): void
    {
        Schema::create('tenant_inventory_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')->cascadeOnDelete();
            $table->foreignUuid('location_id')
                ->constrained('tenant_locations')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('tenant_sales')->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->constrained('tenant_sale_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 12)->default('active'); // active | released | consumed
            $table->string('released_reason', 32)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // The register asks "how much of this item is held HERE" per
            // keystroke; this is the index that question hits.
            $table->index(['inventory_item_id', 'location_id', 'status'], 'tir_item_loc_status_idx');
            $table->index(['sale_id', 'status'], 'tir_sale_status_idx');
        });

        Schema::table('tenant_inventory_item_locations', function (Blueprint $table) {
            // Cache of SUM(quantity) over active reservations. Maintained only
            // by ReservationService; never set by hand.
            $table->integer('reserved_count')->default(0)->after('computed_stock_count');
        });

        $this->appendEnum('tenant_inventory_movements', 'movement_type', ['reserve', 'release'], 'NOT NULL');
        $this->appendEnum('tenant_sales', 'payment_status', ['layaway'], 'NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('tenant_inventory_reservations')->exists()) {
            throw new RuntimeException('Reservations exist; refusing to drop the table they live in.');
        }

        Schema::dropIfExists('tenant_inventory_reservations');

        Schema::table('tenant_inventory_item_locations', function (Blueprint $table) {
            $table->dropColumn('reserved_count');
        });
        // Enum values are left in place: removing them would truncate rows.
    }
};
