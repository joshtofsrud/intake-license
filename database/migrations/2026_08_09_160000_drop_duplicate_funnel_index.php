<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-MKTFIX — tenant_funnel_events already carried an index on
// (tenant_id, event_type, created_at) named tfe_tenant_event_time. The earlier
// migration checked for an index by NAME, not by columns, so it created a
// second identical one. Drop the duplicate; keep the original.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_funnel_events')) {
            return;
        }

        $names = collect(DB::select('SHOW INDEX FROM tenant_funnel_events'))
            ->pluck('Key_name')->unique();

        if ($names->contains('tfe_tenant_type_created_idx') && $names->contains('tfe_tenant_event_time')) {
            DB::statement('DROP INDEX tfe_tenant_type_created_idx ON tenant_funnel_events');
        }
    }

    public function down(): void
    {
        // Deliberately not recreated — it was redundant.
    }
};
