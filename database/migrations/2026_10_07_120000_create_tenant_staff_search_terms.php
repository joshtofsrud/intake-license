<?php
// staff search vocabulary for typo correction.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_staff_search_terms')) {
            return;
        }
        Schema::create('tenant_staff_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('term', 60);
            $table->string('soundex', 10);
            $table->unsignedInteger('freq')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'term'], 'tsst_tenant_term_unique');
            $table->index(['tenant_id', 'soundex'], 'tsst_tenant_soundex_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_staff_search_terms');
    }
};
