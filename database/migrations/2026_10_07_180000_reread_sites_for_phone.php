<?php
// MARKER-SITE-SCAN-PHONE — the website pass now pulls phone numbers. Shops it
// already read without finding a phone are queued to be read once more; the
// scheduler picks them up like any unread shop. Data only, no schema change.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('sales_prospects', 'site_scanned_at')) return;
        DB::table('sales_prospects')
            ->whereNotNull('site_scanned_at')
            ->whereIn('site_scan_status', ['ok', 'nothing_found'])
            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->update(['site_scanned_at' => null]);
    }

    public function down(): void {}
};
