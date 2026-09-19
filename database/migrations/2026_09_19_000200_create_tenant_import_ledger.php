<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-IMPORT-MATCH — the ledger. One row per input row, per phase.
 *
 * `phase` is preview or run. A preview writes what WOULD happen; a run writes
 * what DID. Both are kept so "the preview said 40 updates and the run did 38"
 * is answerable line by line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_import_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('import_id')->index();
            $t->string('phase', 8);                 // preview | run
            $t->unsignedInteger('line');
            // create | update | unchanged | skipped | unmatched | error | possible_duplicate
            $t->string('outcome', 24);
            $t->string('reason', 255)->nullable();
            $t->string('match_key', 24)->nullable(); // sku | upc | mpn | email | phone | name | null
            $t->uuid('matched_id')->nullable();
            $t->string('matched_label', 191)->nullable();
            $t->json('changes')->nullable();
            $t->json('cells')->nullable();
            $t->timestamps();

            $t->index(['import_id', 'phase', 'outcome']);
            $t->unique(['import_id', 'phase', 'line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_import_ledger');
    }
};
