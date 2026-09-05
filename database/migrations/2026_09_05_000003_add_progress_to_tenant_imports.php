<?php
// MARKER-IMPORT-PROGRESS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_imports', function (Blueprint $table) {
            $table->unsignedInteger('progress_done')->default(0)->after('totals');
            $table->unsignedInteger('progress_total')->default(0)->after('progress_done');
            $table->string('progress_stage', 20)->nullable()->after('progress_total');
            $table->timestamp('progress_seen_at')->nullable()->after('progress_stage');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_imports', function (Blueprint $table) {
            $table->dropColumn(['progress_done', 'progress_total', 'progress_stage', 'progress_seen_at']);
        });
    }
};
