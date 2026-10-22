<?php
// MARKER-DEEP-PASS: when the deep website pass last read a shop, and what it got.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'deep_scanned_at'))  $t->timestamp('deep_scanned_at')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'deep_scan_status')) $t->string('deep_scan_status', 24)->nullable();
        });
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->index('deep_scanned_at', 'sales_prospects_deep_scanned_index')); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->dropIndex('sales_prospects_deep_scanned_index')); } catch (\Throwable $e) {}
        Schema::table('sales_prospects', function (Blueprint $t) {
            foreach (['deep_scanned_at', 'deep_scan_status'] as $c) if (Schema::hasColumn('sales_prospects', $c)) $t->dropColumn($c);
        });
    }
};
