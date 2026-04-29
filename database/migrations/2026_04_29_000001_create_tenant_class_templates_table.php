<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_class_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('default_capacity');

            $table->foreignUuid('service_item_id')
                ->nullable()
                ->constrained('tenant_service_items')
                ->nullOnDelete();

            $table->foreignUuid('instructor_resource_id')
                ->nullable()
                ->constrained('tenant_resources')
                ->nullOnDelete();

            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('rrule')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_templates');
    }
};
