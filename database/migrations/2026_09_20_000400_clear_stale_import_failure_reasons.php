<?php
// MARKER-IMPORT-RESULTS — imports that finished 'done' but still carry the
// failure reason from an earlier attempt. Platform-wide data repair across
// every tenant, deliberately not tenant-filtered. Data only; nothing to undo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_imports')
            ->where('status', 'done')
            ->whereNotNull('failure_reason')
            ->update(['failure_reason' => null]);
    }

    public function down(): void
    {
        // Nothing to restore — the cleared reasons were wrong.
    }
};
