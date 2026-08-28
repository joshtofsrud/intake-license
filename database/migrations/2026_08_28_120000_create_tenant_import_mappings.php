<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT-PRESETS — a reusable column mapping. Stores the map and the
// conflict rules only: never the file, never a row of anyone's data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_import_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);                 // customers | inventory
            $table->string('name');

            $table->json('mapping');                    // column index => [field, dir]
            $table->json('options')->nullable();        // mode, direction, inventory extras

            $table->string('header_hash', 40);          // sha1 of the normalised header row
            $table->json('header')->nullable();         // the names themselves, for display

            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            // One name per type per tenant — saving the same name updates it.
            $table->unique(['tenant_id', 'type', 'name']);
            $table->index(['tenant_id', 'type', 'header_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_import_mappings');
    }
};
