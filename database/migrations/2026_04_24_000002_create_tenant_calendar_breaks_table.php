<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_calendar_breaks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // null resource_id = shop-wide break (all resources blocked)
            $table->foreignUuid('resource_id')->nullable()
                  ->constrained('tenant_resources')->cascadeOnDelete();

            $table->string('label');

            // Time window for this break (or the first instance, if recurring)
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // Recurrence — structured from day one
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly'])->nullable();
            $table->json('recurrence_config')->nullable();
            $table->date('recurrence_until')->nullable();

            // Who created it (nullable because staff can be removed)
            $table->foreignUuid('created_by_user_id')->nullable();

            $table->timestamps();

            // Common query pattern: "breaks for this tenant, this resource, this date range"
            $table->index(['tenant_id', 'resource_id', 'starts_at']);

            // Recurrence expansion query: "find all recurring breaks still active"
            $table->index(['tenant_id', 'is_recurring', 'recurrence_until'], 'breaks_tenant_recurring_until_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_calendar_breaks');
    }
};
