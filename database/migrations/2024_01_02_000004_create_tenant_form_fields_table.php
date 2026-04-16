<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_form_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_form_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('tenant_form_sections')->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->string('field_type', 40);  // text, email, tel, textarea, select, checkbox, date
            $table->string('label');
            $table->string('placeholder')->nullable();
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_core')->default(false);
            $table->string('width', 10)->default('full');    // full, half
            $table->json('options')->nullable();             // for select/radio/checkbox
            $table->json('condition')->nullable();           // conditional logic
            $table->json('style_json')->nullable();          // extra styling
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_form_fields');
        Schema::dropIfExists('tenant_form_sections');
    }
};
