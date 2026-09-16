<?php
// MARKER-SALES-ROUTE — additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'import_batch')) {
                $t->string('import_batch', 40)->nullable()->after('source_url')->index();
            }
        });
    }

    public function down(): void {}
};
