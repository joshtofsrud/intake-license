<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function ($table) {
            $table->dropForeign(['license_id']);
        });

        DB::statement('ALTER TABLE tenants MODIFY license_id CHAR(36) NULL');

        Schema::table('tenants', function ($table) {
            $table->foreign('license_id')
                  ->references('id')->on('licenses')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function ($table) {
            $table->dropForeign(['license_id']);
        });

        DB::statement('ALTER TABLE tenants MODIFY license_id CHAR(36) NOT NULL');

        Schema::table('tenants', function ($table) {
            $table->foreign('license_id')
                  ->references('id')->on('licenses')
                  ->cascadeOnDelete();
        });
    }
};
