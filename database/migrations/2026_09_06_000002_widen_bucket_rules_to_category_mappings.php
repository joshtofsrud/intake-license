<?php
// MARKER-CAT-MAP

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_bucket_rules', function (Blueprint $table) {
            $table->string('source_kind', 16)->default('distributor')->after('tenant_id');
            $table->string('source_name', 64)->default('UNKNOWN')->after('source_kind');
            $table->string('set_by', 12)->default('mapper')->after('category_id');
            $table->uuid('set_by_user_id')->nullable()->after('set_by');
            $table->dropUnique(['tenant_id', 'bucket_key']);
            $table->unique(['tenant_id', 'source_kind', 'source_name', 'bucket_key'], 'tbr_source_bucket_unique');
        });

        // Items imported from a CSV keep the file's category string, so the
        // mapper can bucket them by it and a rule can find them again.
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->string('source_category', 160)->nullable()->after('category_id');
            $table->string('source_name', 64)->nullable()->after('source_category');
            $table->index(['tenant_id', 'source_name', 'source_category'], 'tii_source_cat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropIndex('tii_source_cat_idx');
            $table->dropColumn(['source_category', 'source_name']);
        });
        Schema::table('tenant_bucket_rules', function (Blueprint $table) {
            $table->dropUnique('tbr_source_bucket_unique');
            $table->unique(['tenant_id', 'bucket_key']);
            $table->dropColumn(['source_kind', 'source_name', 'set_by', 'set_by_user_id']);
        });
    }
};
