<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-209 — tenant-controlled invoice footer terms.
 * Nullable, no default. Nothing prints unless the tenant sets it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenants')) return;
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'invoice_footer_terms')) {
                $t->text('invoice_footer_terms')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenants')) return;
        Schema::table('tenants', function (Blueprint $t) {
            if (Schema::hasColumn('tenants', 'invoice_footer_terms')) {
                $t->dropColumn('invoice_footer_terms');
            }
        });
    }
};
