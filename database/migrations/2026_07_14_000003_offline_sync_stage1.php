<?php

// MARKER-OFFLINE-SYNC — stage 1: idempotent sale replay + add-on availability.
// client_uuid lets offline-queued sales replay safely (same uuid = same sale).
// The offline_sync addon becomes purchasable on every plan, Solo included —
// not bundled into any tier for now.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->uuid('client_uuid')->nullable()->after('sale_number');
            $t->unique(['tenant_id', 'client_uuid']);
        });

        DB::table('addons')->where('code', 'offline_sync')->update([
            'included_in_plans' => null,
            'min_plan_tier'     => null,
            'description'       => 'The register keeps selling through internet outages — cached catalog, queued sales, automatic sync on reconnect.',
            'tooltip'           => "Keep selling through network outages. Sales queue on-device and sync when you're back online.",
        ]);
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'client_uuid']);
            $t->dropColumn('client_uuid');
        });
    }
};
