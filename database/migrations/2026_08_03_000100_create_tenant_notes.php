<?php

// MARKER-OLD-SCHOOL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pad.
 *
 * customer_id is a POINTER, not a filing. It exists so a note can be found
 * and surfaced next to the person it concerns; it is not that customer's
 * history, which lives in tenant_customer_notes and is untouched by this.
 *
 * completed_at doubles as the archive: open is null, crossed off is a time.
 * A third "archived" state was considered and dropped — a pad has two piles,
 * not three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Where it was written. Nullable because a note is not location
            // work — it just helps a multi-location shop filter later.
            $t->foreignUuid('location_id')
                ->nullable()
                ->constrained('tenant_locations')
                ->nullOnDelete();

            $t->text('body');

            // Pointer, not history. nullOnDelete so removing a customer
            // leaves the note readable rather than deleting someone's
            // reminder along with them.
            $t->foreignUuid('customer_id')
                ->nullable()
                ->constrained('tenant_customers')
                ->nullOnDelete();

            $t->foreignUuid('created_by')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $t->timestamp('completed_at')->nullable();
            $t->foreignUuid('completed_by')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $t->timestamps();

            // The two reads that matter: the open pile, and open notes for
            // one customer (which is what the banner will ask for).
            $t->index(['tenant_id', 'completed_at']);
            $t->index(['tenant_id', 'customer_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notes');
    }
};
