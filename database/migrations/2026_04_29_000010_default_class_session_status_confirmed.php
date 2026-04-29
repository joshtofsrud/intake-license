<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_class_sessions', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'confirmed', 'cancelled', 'completed'])
                ->default('confirmed')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_class_sessions', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'confirmed', 'cancelled', 'completed'])
                ->default('scheduled')
                ->change();
        });
    }
};
