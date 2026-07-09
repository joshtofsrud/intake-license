<?php
// MARKER-PATCH-622 — shop search stage 2 schema.
// tenant_search_terms: per-tenant vocabulary (words from item names/brands/
//   SKUs) used for typo correction. Rebuilt nightly + on demand.
// tenant_search_rules: tenant-managed synonyms and redirects, edited from the
//   Traffic report's Search rules card.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('term', 60);
            $table->string('soundex', 10)->index();
            $table->unsignedInteger('freq')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'term'], 'tst_tenant_term_unique');
            $table->index(['tenant_id', 'soundex'], 'tst_tenant_soundex_idx');
        });

        Schema::create('tenant_search_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type', 12);            // synonym | redirect
            $table->string('from_term', 120);      // the query/word customers type
            $table->string('to_value', 300);       // synonym target word OR redirect URL
            $table->string('label', 120)->nullable(); // redirect display label
            $table->unsignedInteger('hits')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'from_term'], 'tsr_tenant_type_from_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_search_rules');
        Schema::dropIfExists('tenant_search_terms');
    }
};

