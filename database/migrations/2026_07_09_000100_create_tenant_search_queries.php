<?php
// MARKER-PATCH-621 — shop search analytics: every instant-search query logs
// here (query, results count, session) so the Traffic report can surface top
// searches and zero-result searches. Keystroke prefixes collapse per session.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_search_queries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('session_id', 64)->nullable();
            $table->string('query', 190);
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'tsq_tenant_created_idx');
            $table->index(['tenant_id', 'session_id', 'created_at'], 'tsq_tenant_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_search_queries');
    }
};

