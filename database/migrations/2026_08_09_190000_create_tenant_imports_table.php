<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT1 — one row per import attempt. Keeps the file, the mapping and
// the outcome so a bad run can be diagnosed instead of guessed at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);                 // customers | inventory
            $table->string('original_filename');
            $table->string('stored_path');              // local disk, never web-served
            $table->string('delimiter', 4)->default(',');
            $table->string('encoding', 20)->default('UTF-8');
            $table->boolean('has_header')->default(true);

            $table->json('columns')->nullable();        // header names as found
            $table->json('mapping')->nullable();        // column index => [field, dir]
            $table->json('options')->nullable();        // mode, default direction, …
            $table->json('totals')->nullable();         // created/updated/skipped/errors

            $table->string('status', 20)->default('draft'); // draft|previewed|running|done|failed
            $table->text('failure_reason')->nullable();
            $table->string('error_path')->nullable();   // generated error CSV

            $table->uuid('created_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_imports');
    }
};
