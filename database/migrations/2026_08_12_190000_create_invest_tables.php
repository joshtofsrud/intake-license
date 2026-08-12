<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-INVEST-SITE
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invest_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invest_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invest_token_id')->nullable()->constrained('invest_tokens')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('note', 1000)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invest_leads');
        Schema::dropIfExists('invest_tokens');
    }
};
