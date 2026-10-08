<?php
// stored search text and recent sales for staff search.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_items', 'search_text')) {
                $t->text('search_text')->nullable();
            }
            if (! Schema::hasColumn('tenant_inventory_items', 'recent_sales')) {
                $t->unsignedInteger('recent_sales')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $t) {
            $t->dropColumn(['search_text', 'recent_sales']);
        });
    }
};
