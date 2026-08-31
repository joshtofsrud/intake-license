<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-CAMPAIGN-V2A — inbox preview line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_campaigns', 'preheader')) {
                $table->string('preheader', 200)->nullable()->after('subject');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            $table->dropColumn('preheader');
        });
    }
};
