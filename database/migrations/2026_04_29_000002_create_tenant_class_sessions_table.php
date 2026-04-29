<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_class_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('class_template_id')
                ->constrained('tenant_class_templates')
                ->cascadeOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->string('instructor_snapshot')->nullable();
            $table->foreignUuid('instructor_resource_id')
                ->nullable()
                ->constrained('tenant_resources')
                ->nullOnDelete();
            $table->unsignedSmallInteger('capacity_snapshot');

            $table->enum('status', ['scheduled', 'confirmed', 'cancelled', 'completed'])
                ->default('scheduled');

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['class_template_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_sessions');
    }
};
