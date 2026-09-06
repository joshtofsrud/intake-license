<?php
// MARKER-SOURCE-CAT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Rules the mapper inferred are wrong in two ways: recorded against a
        // whole bucket when only part of it was assigned, and against
        // source_name UNKNOWN so one rule spoke for every distributor. Nothing
        // reads them after this patch; leaving them would only mislead.
        $n = DB::table('tenant_bucket_rules')->where('set_by', 'mapper')->delete();
        Log::info("MARKER-SOURCE-CAT: removed {$n} inferred rule(s)");
    }

    public function down(): void
    {
        // Not restorable, and not worth restoring.
    }
};
