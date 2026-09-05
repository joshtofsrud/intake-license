<?php
// MARKER-CATALOG-IMPORT-ALL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_change_batches', function (Blueprint $table) {
            $table->string('status', 20)->default('done')->after('item_count');
            $table->unsignedInteger('progress_done')->default(0)->after('status');
            $table->unsignedInteger('progress_total')->default(0)->after('progress_done');
            $table->string('progress_stage', 20)->nullable()->after('progress_total');
            $table->timestamp('progress_seen_at')->nullable()->after('progress_stage');
            $table->json('result')->nullable()->after('progress_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_change_batches', function (Blueprint $table) {
            $table->dropColumn(['status', 'progress_done', 'progress_total',
                                'progress_stage', 'progress_seen_at', 'result']);
        });
    }
};
