<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->boolean('tax_locked')
              ->default(false)
              ->after('total_cents');
        });

        DB::table('tenant_sales')
            ->whereNotNull('appointment_id')
            ->update(['tax_locked' => true]);
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropColumn('tax_locked');
        });
    }
};
