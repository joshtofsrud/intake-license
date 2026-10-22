<?php
// MARKER-OUTREACH-FAST: Email outreach filters and sorts on these columns. With them in one
// index, MySQL counts and sorts the queue from the index alone instead of reading every
// prospect's full row (slow once 185,000 shops were loaded).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        try {
            Schema::table('sales_prospects', fn (Blueprint $t) => $t->index(
                ['stage', 'emailed_at', 'channel_id', 'state', 'email_skipped_at', 'lead_score', 'created_at', 'email'],
                'sales_prospects_outreach_index'));
        } catch (\Throwable $e) { report($e); }
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->index('link_clicked_at', 'sales_prospects_clicked_index')); } catch (\Throwable $e) { report($e); }
    }

    public function down(): void
    {
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->dropIndex('sales_prospects_outreach_index')); } catch (\Throwable $e) {}
        try { Schema::table('sales_prospects', fn (Blueprint $t) => $t->dropIndex('sales_prospects_clicked_index')); } catch (\Throwable $e) {}
    }
};
