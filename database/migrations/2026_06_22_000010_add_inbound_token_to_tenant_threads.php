<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-403 — per-thread inbound email token (unified inbox email replies).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_threads', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_threads', 'inbound_token')) {
                // Nullable + unique: MySQL permits multiple NULLs, so adding the
                // unique index before the backfill is safe on existing rows.
                $table->string('inbound_token', 32)->nullable()->unique()->after('subject');
            }
        });

        // Backfill existing threads with an unguessable token. pluck() first so
        // we're not chunking a result set we're simultaneously mutating.
        foreach (DB::table('tenant_threads')->whereNull('inbound_token')->pluck('id') as $id) {
            DB::table('tenant_threads')
                ->where('id', $id)
                ->update(['inbound_token' => bin2hex(random_bytes(12))]); // 24 hex chars
        }
    }

    public function down(): void
    {
        Schema::table('tenant_threads', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_threads', 'inbound_token')) {
                $table->dropUnique(['inbound_token']);
                $table->dropColumn('inbound_token');
            }
        });
    }
};
