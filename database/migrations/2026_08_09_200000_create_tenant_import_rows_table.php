<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT2 — what each imported row actually did, so it can be undone.
// One row per record touched. `before` holds ONLY the fields that changed,
// with their prior values — enough to restore, small enough to keep.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_id')->index();
            $table->uuid('tenant_id')->index();

            $table->string('action', 12);              // created | updated
            $table->string('record_type', 24);         // customer | item | category | vendor
            $table->uuid('record_id');

            $table->json('before')->nullable();        // changed fields, prior values
            $table->integer('stock_delta')->nullable();// signed, for reversal
            $table->uuid('location_id')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('kept_reason')->nullable(); // why undo left it alone

            $table->index(['import_id', 'action']);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_import_rows');
    }
};
