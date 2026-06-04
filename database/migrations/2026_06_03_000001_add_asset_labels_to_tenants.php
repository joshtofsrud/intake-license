<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenants')) return;
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'asset_label_singular')) {
                $table->string('asset_label_singular', 30)->default('item')->after('multi_asset_enabled');
            }
            if (!Schema::hasColumn('tenants', 'asset_label_plural')) {
                $table->string('asset_label_plural', 30)->default('items')->after('asset_label_singular');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenants')) return;
        Schema::table('tenants', function (Blueprint $table) {
            foreach (['asset_label_singular', 'asset_label_plural'] as $c) {
                if (Schema::hasColumn('tenants', $c)) $table->dropColumn($c);
            }
        });
    }
};
