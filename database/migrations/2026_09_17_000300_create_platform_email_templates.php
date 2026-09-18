<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PLATFORM-TEMPLATES — an override per template key.
 *
 * A row here MEANS "this one is customised". Absence means the shipped Blade
 * file renders, so reverting is a delete and the default can never drift out
 * of sync with a copy of itself stored in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_email_templates', function (Blueprint $t) {
            $t->string('key', 48)->primary();
            $t->string('subject', 200)->nullable();
            $t->text('body')->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('updated_by', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_email_templates');
    }
};
