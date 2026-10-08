<?php
// MARKER-PAGE-WIDTH — the widest any section on a page can go. null = site default.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_pages', 'page_width')) {
            Schema::table('tenant_pages', function (Blueprint $t) {
                $t->unsignedSmallInteger('page_width')->nullable()->after('og_image_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_pages', 'page_width')) {
            Schema::table('tenant_pages', function (Blueprint $t) { $t->dropColumn('page_width'); });
        }
    }
};
