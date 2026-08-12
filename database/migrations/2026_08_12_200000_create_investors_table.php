<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-ADMIN
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('entity')->nullable();
            $table->unsignedInteger('amount')->default(0);          // committed, whole dollars
            $table->unsignedInteger('amount_received')->default(0); // wired, whole dollars
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('funding_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
