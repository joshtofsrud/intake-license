<?php

// MARKER-REGISTER-RECON-DISPLAY — per-register display logo choice.
// auto = light logo, falling back to main; main/light force one; none hides it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->string('display_logo', 10)->default('auto')->after('display_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->dropColumn('display_logo');
        });
    }
};
