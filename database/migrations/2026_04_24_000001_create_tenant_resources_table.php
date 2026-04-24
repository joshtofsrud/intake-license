<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Display
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->char('color_hex', 7);

            // Type discriminator — staff/slot/space cover v1;
            // future modes (class, equipment) can extend
            $table->enum('type', ['staff', 'slot', 'space'])->default('staff');

            // Optional link to a user row when type=staff
            $table->foreignUuid('staff_user_id')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // Future-proofing for per-resource config
            // (working hours override, capacity weight, etc)
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_resources');
    }
};
