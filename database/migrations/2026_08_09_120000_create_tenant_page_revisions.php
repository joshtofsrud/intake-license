<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REWIND — one row per restore point. `snapshot` holds the page meta
// plus every section, so a restore needs no other table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_page_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Deliberately NOT a foreign key: a revision has to outlive the
            // page it describes, or deleting a page would delete its own undo.
            $table->uuid('page_id')->index();

            $table->string('label');                       // "Deleted Hero"
            $table->string('actor_name')->nullable();      // who triggered it
            $table->unsignedSmallInteger('section_count')->default(0);
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_page_revisions');
    }
};
