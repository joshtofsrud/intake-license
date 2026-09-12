<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ITEM-MERGE — merge_out / merge_in on the movement_type enum.
 *
 * Deliberately NOT reusing transfer_out / transfer_in: a transfer means stock
 * physically moved between locations, and a multi-location shop reads that
 * report. A merge moves stock between RECORDS at the same location. Sharing
 * the type would make those reports quietly wrong.
 *
 * Enum values are only added, never removed or reordered, so existing rows are
 * untouched. The down() drops them again, which is safe only while no merge
 * has been recorded — hence the guard.
 */
return new class extends Migration
{
    private const BASE = "'sale','sale_void','refund','receive','adjustment','transfer_out','transfer_in','initial'";

    public function up(): void
    {
        DB::statement(
            "ALTER TABLE tenant_inventory_movements
             MODIFY COLUMN movement_type ENUM(" . self::BASE . ",'merge_out','merge_in') NOT NULL"
        );
    }

    public function down(): void
    {
        $inUse = DB::table('tenant_inventory_movements')
            ->whereIn('movement_type', ['merge_out', 'merge_in'])
            ->exists();

        if ($inUse) {
            throw new RuntimeException(
                'Refusing to drop merge_out/merge_in: movements of that type exist and '
                . 'would be destroyed. Reverse the merges first if this is really wanted.'
            );
        }

        DB::statement(
            "ALTER TABLE tenant_inventory_movements
             MODIFY COLUMN movement_type ENUM(" . self::BASE . ") NOT NULL"
        );
    }
};
