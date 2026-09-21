<?php
// MARKER-SNAPSHOT-OVERWRITE — one availability row per tenant + distributor +
// variant. Keeps the newest row for each (highest id = last written), drops
// the rest by swapping in a cleaned copy, and adds the unique key the sync's
// upsert overwrites on. Not reversible: the dropped rows were unread history.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $t = 'distributor_availability_snapshots';

        if (DB::select("SHOW INDEX FROM {$t} WHERE Key_name = 'das_item_unique'")) {
            return; // already one row per item
        }

        Schema::dropIfExists('das_latest');
        DB::statement("CREATE TABLE das_latest LIKE {$t}");
        DB::statement('ALTER TABLE das_latest ADD UNIQUE KEY das_item_unique (tenant_id, distributor_code, distributor_variant_no)');

        DB::statement("
            INSERT INTO das_latest
            SELECT s.* FROM {$t} s
            JOIN (SELECT MAX(id) AS id FROM {$t}
                  GROUP BY tenant_id, distributor_code, distributor_variant_no) k ON k.id = s.id
        ");

        // Atomic: readers see the old table or the clean one, never neither.
        DB::statement("RENAME TABLE {$t} TO das_history_dropped, das_latest TO {$t}");
        Schema::drop('das_history_dropped');
    }

    public function down(): void
    {
        // Nothing to restore — the dropped rows were repeats nobody read.
    }
};
