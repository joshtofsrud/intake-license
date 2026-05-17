<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes thread per special order.
 *
 * Two kinds of notes:
 *   - User-authored ("called QBP 5/16, they said maybe Wednesday")
 *     — tenant_user_id set, is_system = false.
 *   - System-authored ("auto-arrived from QBP-RCV-2391")
 *     — tenant_user_id null, is_system = true.
 *
 * No editing or threading in v1. Notes are append-only, displayed
 * in chronological order. Soft-delete only — staff can hide a note
 * but it never goes away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_special_order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('special_order_id')
                ->constrained('tenant_special_orders')
                ->cascadeOnDelete();

            $table->foreignUuid('tenant_user_id')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $table->boolean('is_system')->default(false);

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index('special_order_id', 'tson_so_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_special_order_notes');
    }
};
