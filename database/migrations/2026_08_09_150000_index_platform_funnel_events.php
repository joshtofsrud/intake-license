<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-MKTTRAFFIC — marketing traffic lands in tenant_funnel_events under the
// platform tenant, so the report filters by (tenant_id, event_type, created_at)
// exactly like the tenant one. Add the index only if it isn't already there.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_funnel_events')) {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM tenant_funnel_events'))
            ->pluck('Key_name')->unique();

        if (! $existing->contains('tfe_tenant_type_created_idx')) {
            DB::statement(
                'CREATE INDEX tfe_tenant_type_created_idx
                 ON tenant_funnel_events (tenant_id, event_type, created_at)'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_funnel_events')) {
            DB::statement('DROP INDEX tfe_tenant_type_created_idx ON tenant_funnel_events');
        }
    }
};
