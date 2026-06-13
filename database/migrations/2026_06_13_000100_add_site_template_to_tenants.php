<?php
// MARKER-PATCH-260

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site templates: a tenant picks one of a handful of named, bike-shop-tuned
 * looks. The active key lives in tenants.site_template; the resolved token
 * bundle (including the extended tokens the discrete design columns don't
 * model -- button radius/style, heading weight/case, surface + muted tones)
 * lives in tenants.design_tokens.
 *
 * Existing tenants keep whatever colours/fonts they already set, so they map
 * to 'custom' with a null token blob until they explicitly choose a template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('site_template', 24)->default('custom')->after('tagline');
            $t->json('design_tokens')->nullable()->after('site_template');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['site_template', 'design_tokens']);
        });
    }
};
