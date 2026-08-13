<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-SETUP
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raise_message_templates', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('invest_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('label');
            $table->string('path');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invest_documents');
        Schema::dropIfExists('raise_message_templates');
    }
};
