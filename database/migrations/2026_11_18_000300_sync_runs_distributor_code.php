<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SYNC-PROGRESS: a "Refresh" from one distributor box records which
// distributor it was for, so the box can find and follow its own run.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_distributor_sync_runs', 'distributor_code')) {
            return;
        }
        Schema::table('tenant_distributor_sync_runs', function (Blueprint $table) {
            $table->string('distributor_code', 16)->nullable()->after('dry_run');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_distributor_sync_runs', 'distributor_code')) {
            Schema::table('tenant_distributor_sync_runs', function (Blueprint $table) {
                $table->dropColumn('distributor_code');
            });
        }
    }
};
