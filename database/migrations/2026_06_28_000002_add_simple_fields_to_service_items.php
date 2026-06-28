<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-FLOW-2 — which service items appear in Simple mode's curated menu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->boolean('simple_enabled')->default(false)->after('is_active');
            $table->integer('simple_sort')->default(0)->after('simple_enabled');
            $table->string('simple_tagline', 160)->nullable()->after('simple_sort');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->dropColumn(['simple_enabled', 'simple_sort', 'simple_tagline']);
        });
    }
};
