<?php
// MARKER-CAMPAIGN-AUDIENCE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_audiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            // The RULES are stored, never the resulting customer ids: a saved
            // audience re-runs when a campaign sends, so it is always current
            // rather than a frozen copy of who matched the day it was saved.
            $table->json('rules');
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_audiences');
    }
};
