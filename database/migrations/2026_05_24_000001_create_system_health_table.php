<?php
// MARKER-PATCH-132

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_health', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value');
            $t->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health');
    }
};
