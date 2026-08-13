<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — per-line metadata. First use: a gift_card line stores
// its gift details here on the DRAFT row, so drafts/quotes survive and
// commitDraft activates from the row itself. Additive (expand/contract rule).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
