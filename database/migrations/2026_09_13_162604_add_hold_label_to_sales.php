<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-HOLD — the name someone gave a parked cart.
 *
 * Its presence is what separates a sale someone chose to keep from one the
 * autosave happened to leave behind. Null means recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->string('hold_label', 60)->nullable()->after('notes');
            $table->timestamp('held_at')->nullable()->after('hold_label');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropColumn(['hold_label', 'held_at']);
        });
    }
};
