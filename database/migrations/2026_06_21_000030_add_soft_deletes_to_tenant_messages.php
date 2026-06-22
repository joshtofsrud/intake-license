<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-401 — soft-delete for inbox messages.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_messages', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_messages', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_messages', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
