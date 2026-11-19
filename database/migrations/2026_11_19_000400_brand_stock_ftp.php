<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-BRAND-STOCK: a brand's stock file FTP folder (encrypted), one per brand. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_distributor_connections', 'stock_ftp')) {
            Schema::table('platform_distributor_connections', fn (Blueprint $t) => $t->text('stock_ftp')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_distributor_connections', 'stock_ftp')) {
            Schema::table('platform_distributor_connections', fn (Blueprint $t) => $t->dropColumn('stock_ftp'));
        }
    }
};
