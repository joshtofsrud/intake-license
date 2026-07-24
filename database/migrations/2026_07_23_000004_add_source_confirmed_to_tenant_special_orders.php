<?php

// MARKER-SO-ORIGIN — "still needed" is a decision, so it has to persist.
// Without it the queue would re-flag the same orphaned order every time
// someone looked at it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->timestamp('source_confirmed_at')->nullable();
            $t->uuid('source_confirmed_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->dropColumn(['source_confirmed_at', 'source_confirmed_by_user_id']);
        });
    }
};
