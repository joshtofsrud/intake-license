<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-IMPORT-QUEUE — a cancel the job actually honours. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_imports', function (Blueprint $t) {
            $t->timestamp('cancel_requested_at')->nullable()->after('progress_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_imports', function (Blueprint $t) {
            $t->dropColumn('cancel_requested_at');
        });
    }
};
