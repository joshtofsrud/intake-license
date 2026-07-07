<?php
// MARKER-AGENCIES-ATTR — Deal registration: which agency/rep owns a prospect.
// Attribution lives on the prospect and follows it through conversion —
// a won prospect's tenant_id + agency_id is the commission join (chunk 2).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'agency_id')) {
                $t->uuid('agency_id')->nullable()->after('channel_id');
            }
            if (! Schema::hasColumn('sales_prospects', 'sales_rep_id')) {
                $t->uuid('sales_rep_id')->nullable()->after('agency_id');
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->foreign('agency_id', 'sales_prospects_agency_fk')
                  ->references('id')->on('sales_agencies')->nullOnDelete();
            } catch (\Throwable $e) {}
            try {
                $t->foreign('sales_rep_id', 'sales_prospects_rep_fk')
                  ->references('id')->on('sales_reps')->nullOnDelete();
            } catch (\Throwable $e) {}
            try { $t->index('agency_id', 'sales_prospects_agency_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropForeign('sales_prospects_agency_fk'); } catch (\Throwable $e) {}
            try { $t->dropForeign('sales_prospects_rep_fk'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_agency_index'); } catch (\Throwable $e) {}
            foreach (['agency_id', 'sales_rep_id'] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
