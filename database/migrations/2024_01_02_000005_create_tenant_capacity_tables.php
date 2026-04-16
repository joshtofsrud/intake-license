<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_capacity_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * rule_type = 'default': day_of_week set (0=Sun…6=Sat), specific_date null
             * rule_type = 'override': specific_date set, day_of_week null
             */
            $table->enum('rule_type', ['default', 'override'])->default('default');
            $table->tinyInteger('day_of_week')->unsigned()->nullable();
            $table->date('specific_date')->nullable();
            $table->unsignedSmallInteger('max_appointments')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rule_type']);
            $table->index(['tenant_id', 'specific_date']);
        });

        Schema::create('tenant_receiving_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
            $table->text('description')->nullable();
            $table->boolean('ask_for_time')->default(false);
            $table->boolean('ask_for_tracking')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_receiving_methods');
        Schema::dropIfExists('tenant_capacity_rules');
    }
};
