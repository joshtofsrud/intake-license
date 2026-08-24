<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-TILES — both nullable and additive. A null dashboard_view means
// the existing Overview dashboard, which is what every current user gets
// until they choose otherwise.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('dashboard_view', 16)->nullable()->after('admin_theme');
            $table->json('dashboard_tiles')->nullable()->after('dashboard_view');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn(['dashboard_view', 'dashboard_tiles']);
        });
    }
};
