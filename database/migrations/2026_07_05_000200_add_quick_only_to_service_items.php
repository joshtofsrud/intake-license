<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-546 — a service can be quick-flow-only: shown in the Simple
// menu (when simple_enabled) but hidden from the full multi-step flow.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->boolean('quick_only')->default(false)->after('simple_tagline');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->dropColumn('quick_only');
        });
    }
};
