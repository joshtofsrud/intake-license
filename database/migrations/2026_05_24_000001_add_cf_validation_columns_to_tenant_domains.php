<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-125
 *
 * Cloudflare for SaaS returns two sets of DNS records that tenants must
 * add to validate cert issuance with the CA (in addition to Intake's own
 * ownership TXT):
 *
 *   ssl.validation_records       - TXT pair (txt_name / txt_value).
 *                                  Must be re-added on every cert renewal.
 *   ssl.dcv_delegation_records   - CNAME delegation (cname / cname_target).
 *                                  Set-and-forget; handles future renewals.
 *
 * We store the raw arrays as returned by the CF API so the view layer can
 * pick the preferred record shape without parsing assumptions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->json('cf_validation_records')->nullable()->after('cloudflare_hostname_id');
            $table->json('cf_dcv_delegation_records')->nullable()->after('cf_validation_records');
            $table->timestamp('cf_validation_synced_at')->nullable()->after('cf_dcv_delegation_records');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn([
                'cf_validation_records',
                'cf_dcv_delegation_records',
                'cf_validation_synced_at',
            ]);
        });
    }
};
