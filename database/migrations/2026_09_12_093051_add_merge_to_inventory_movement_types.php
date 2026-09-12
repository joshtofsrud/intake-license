<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ITEM-MERGE — merge_out / merge_in on the movement_type enum.
 *
 * Reads the column's CURRENT definition rather than restating it. The first
 * version of this migration listed the values from the create migration and
 * silently dropped 'appointment' and 'appointment_refund', which a later
 * migration had added — MySQL refused with "data truncated", because rows
 * were using them.
 *
 * Appending to what is actually there cannot make that mistake, no matter how
 * many migrations widen this enum between now and the next one.
 */
return new class extends Migration
{
    private function currentValues(): array
    {
        $row = DB::selectOne("
            SELECT COLUMN_TYPE AS t
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'tenant_inventory_movements'
              AND COLUMN_NAME  = 'movement_type'
        ");

        if (! $row || ! preg_match_all("/'((?:[^']|'')*)'/", $row->t, $m)) {
            throw new RuntimeException('Could not read the movement_type enum definition.');
        }

        return array_map(fn ($v) => str_replace("''", "'", $v), $m[1]);
    }

    public function up(): void
    {
        $values = $this->currentValues();
        $adding = array_values(array_diff(['merge_out', 'merge_in'], $values));

        if (! $adding) {
            return; // already there — safe to re-run after a failed attempt
        }

        $all = array_merge($values, $adding);
        $list = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $all));

        DB::statement("ALTER TABLE tenant_inventory_movements
                       MODIFY COLUMN movement_type ENUM({$list}) NOT NULL");
    }

    public function down(): void
    {
        $inUse = DB::table('tenant_inventory_movements')
            ->whereIn('movement_type', ['merge_out', 'merge_in'])
            ->exists();

        if ($inUse) {
            throw new RuntimeException(
                'Refusing to drop merge_out/merge_in: movements of that type exist and '
                . 'would be destroyed. Reverse those merges first if this is really wanted.'
            );
        }

        $values = array_values(array_diff($this->currentValues(), ['merge_out', 'merge_in']));

        if (! $values) {
            return;
        }

        $list = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $values));

        DB::statement("ALTER TABLE tenant_inventory_movements
                       MODIFY COLUMN movement_type ENUM({$list}) NOT NULL");
    }
};
