<?php
// The website pass no longer runs by itself: it reads sites only after Start
// on Find shops. A pass that is running now keeps running; one that was paused
// stays stopped.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $paused = DB::table('sales_settings')->where('key', 'site_scan_paused')->value('value') === '1';
        DB::table('sales_settings')->updateOrInsert(['key' => 'site_scan_on'], ['value' => $paused ? '0' : '1', 'updated_at' => now(), 'created_at' => now()]);
        DB::table('sales_settings')->where('key', 'site_scan_paused')->delete();
    }

    public function down(): void
    {
        $on = DB::table('sales_settings')->where('key', 'site_scan_on')->value('value') === '1';
        DB::table('sales_settings')->updateOrInsert(['key' => 'site_scan_paused'], ['value' => $on ? '0' : '1', 'updated_at' => now(), 'created_at' => now()]);
        DB::table('sales_settings')->where('key', 'site_scan_on')->delete();
    }
};
