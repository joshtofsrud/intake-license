<?php
// MARKER-PATCH-611 — capability layer: granular per-role permissions alongside
// section visibility. NULL = full access (mirrors the sections convention).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('sections');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};

