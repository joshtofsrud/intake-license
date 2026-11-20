<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-CONTEXT: what a POS needs remembered per shop beyond tokens
 * (e.g. a store's own domain prefix). Shape is the adapter's business.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_pos_connections', 'context')) {
            Schema::table('tenant_pos_connections', function (Blueprint $t) {
                $t->json('context')->nullable()->after('external_account_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            $t->dropColumn('context');
        });
    }
};
