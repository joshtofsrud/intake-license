<?php

// MARKER-OFFLINE-SYNC — stage 2: idempotent time-clock punch replay.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_time_punches', function (Blueprint $t) {
            $t->uuid('client_uuid')->nullable()->after('source');
            $t->unique(['tenant_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_time_punches', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'client_uuid']);
            $t->dropColumn('client_uuid');
        });
    }
};
