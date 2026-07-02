<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-481 — actual completion instant (pairs with promised_at for
// late_completion). Nullable, UTC; stamped by the model on first transition into
// a done state. No backfill — historical rows stay null on purpose.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('promised_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
