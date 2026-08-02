<?php

// MARKER-VENDOR-NET-COST — see apply-vendor-discount-and-distributor-link.sh

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            // Percentage off this vendor's cost. 5.25 means 5.25%. Null means
            // no program, which is different from 0 only in intent.
            $t->decimal('program_discount_pct', 5, 2)->nullable()->after('account_number');

            // Which distributor feed this vendor IS, when it is one. Matches
            // tenant_inventory_item_vendors.distributor_code.
            $t->string('distributor_code', 32)->nullable()->after('program_discount_pct');
        });

        // Heal existing installs: the importer named auto-created vendors
        // after the code, so an exact name match is a safe backfill.
        foreach (array_keys((array) config('distributors', [])) as $code) {
            DB::table('tenant_vendors')
                ->whereNull('distributor_code')
                ->whereRaw('LOWER(name) = ?', [strtolower($code)])
                ->update(['distributor_code' => strtolower($code)]);
        }

        // Added AFTER the backfill so a pre-existing duplicate surfaces as a
        // migration failure rather than silently keeping the wrong vendor.
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->unique(['tenant_id', 'distributor_code'], 'tenant_vendors_tenant_distributor_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->dropUnique('tenant_vendors_tenant_distributor_unique');
            $t->dropColumn(['program_discount_pct', 'distributor_code']);
        });
    }
};
