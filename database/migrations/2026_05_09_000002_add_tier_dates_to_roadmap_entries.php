<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap richness pass:
 *   - tier (1-4)               — mirrors internal Tier 1-4 framework
 *   - shipped_on (date)        — when shipped status actually happened
 *   - target_month (date)      — first-of-month for "Targeting July 2026" copy
 *
 * `rough_timeframe` stays for backward compat + items that genuinely don't have
 * a target month ("considering" items, "when X" framings).
 *
 * Backfill: any existing row with status='shipped' gets shipped_on = created_at::date
 * as a best-guess. Manual cleanup welcome but not required.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roadmap_entries', function (Blueprint $t) {
            $t->unsignedTinyInteger('tier')->nullable()->after('status');
            $t->date('shipped_on')->nullable()->after('rough_timeframe');
            $t->date('target_month')->nullable()->after('shipped_on');

            $t->index(['status', 'tier']);
            $t->index('shipped_on');
        });

        // One-time best-guess backfill: shipped rows get shipped_on from created_at.
        DB::statement("
            UPDATE roadmap_entries
               SET shipped_on = DATE(created_at)
             WHERE status = 'shipped'
               AND shipped_on IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('roadmap_entries', function (Blueprint $t) {
            $t->dropIndex(['status', 'tier']);
            $t->dropIndex(['shipped_on']);
            $t->dropColumn(['tier', 'shipped_on', 'target_month']);
        });
    }
};
