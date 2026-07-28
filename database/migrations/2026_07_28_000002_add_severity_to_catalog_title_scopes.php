<?php

// MARKER-FLAG-TUNING — denormalized worst severity, so the page can filter
// "needs attention" on an index instead of unpacking a json column per row.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_scopes', function (Blueprint $t) {
            // null = clean · 'info' = context only · 'warn' · 'bad'
            $t->string('severity', 8)->nullable()->after('flags');
            $t->index(['distributor_code', 'severity'], 'cts_dist_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_scopes', function (Blueprint $t) {
            $t->dropIndex('cts_dist_sev_idx');
            $t->dropColumn('severity');
        });
    }
};
