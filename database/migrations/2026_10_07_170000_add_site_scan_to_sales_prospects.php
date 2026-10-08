<?php
// MARKER-SALES-SITE-SCAN — what the website pass found, and when it last read the site.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'socials'))          $t->json('socials')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'brands'))           $t->json('brands')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'site_scanned_at'))  $t->timestamp('site_scanned_at')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'site_scan_status')) $t->string('site_scan_status', 24)->nullable();
        });
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->index('site_scanned_at', 'sales_prospects_site_scanned_index')); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->dropIndex('sales_prospects_site_scanned_index')); } catch (\Throwable $e) {}
        Schema::table('sales_prospects', function (Blueprint $t) {
            foreach (['socials', 'brands', 'site_scanned_at', 'site_scan_status'] as $c) if (Schema::hasColumn('sales_prospects', $c)) $t->dropColumn($c);
        });
    }
};
