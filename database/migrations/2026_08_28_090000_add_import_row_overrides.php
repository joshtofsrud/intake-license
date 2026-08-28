<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT-MERGE — per-row merge decisions, keyed field => line => csv|keep|blank.
// Kept with the import rather than in the session, so leaving the screen and
// coming back doesn't lose them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_imports', function (Blueprint $table) {
            $table->json('row_overrides')->nullable()->after('mapping');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_imports', function (Blueprint $table) {
            $table->dropColumn('row_overrides');
        });
    }
};
