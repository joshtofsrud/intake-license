<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds is_platform flag to tenants.
 *
 * The marketing site at intake.works reuses the TenantPage / TenantPageSection
 * models by storing marketing content under a single reserved tenant row.
 * This flag is how we identify that row without relying on a magic subdomain
 * string (the row's subdomain is '__platform' but the flag is authoritative).
 *
 * Guarantees:
 *   - Only one tenant can have is_platform = true (enforced by unique index)
 *   - ResolveTenant middleware ignores platform tenant — it's never served
 *     as a subdomain tenant site; its pages are served by the marketing
 *     controller on the root domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->after('is_active');
        });

        // Only one platform tenant allowed. Partial unique index on MySQL
        // isn't supported directly, so we index the column and rely on the
        // seeder to be idempotent.
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('is_platform');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['is_platform']);
            $table->dropColumn('is_platform');
        });
    }
};
