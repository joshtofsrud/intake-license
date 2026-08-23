<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-TPL-MANAGE — "last applied" is the fact that tells you which of
// two similarly-named templates is the dead one.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_shift_templates', function (Blueprint $table) {
            $table->dateTime('last_applied_at')->nullable()->after('created_by');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_shift_templates', function (Blueprint $table) {
            $table->dropColumn('last_applied_at');
        });
    }
};
