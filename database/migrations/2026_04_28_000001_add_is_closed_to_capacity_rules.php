<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closed-toggle for capacity rules. When is_closed=true, the day is closed
 * regardless of open/close/interval values. Spec: saved-closed = lose prior
 * values, so the UI nulls open_time/close_time when toggling closed; this
 * column makes the intent explicit rather than inferred from NULL fields.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_capacity_rules', function (Blueprint $t) {
            $t->boolean('is_closed')->default(false)->after('close_time');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_capacity_rules', function (Blueprint $t) {
            $t->dropColumn('is_closed');
        });
    }
};
