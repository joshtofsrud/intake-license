<?php
// MARKER-PATCH-612B — repair partial 612 migration:
//   • 612 died on the too-long auto index name for time_off_requests' second
//     composite index (MySQL 64-char limit), leaving that index missing and
//     tenant_availability uncreated. This adds both with short explicit names.
//   • Guards make it safe whether or not any piece already exists.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) missing index on time_off_requests — short explicit name.
        $hasIdx = collect(DB::select("SHOW INDEX FROM tenant_time_off_requests"))
            ->pluck('Key_name')->contains('toff_tenant_user_start_idx');
        if (! $hasIdx) {
            Schema::table('tenant_time_off_requests', function (Blueprint $table) {
                $table->index(['tenant_id', 'tenant_user_id', 'starts_at'], 'toff_tenant_user_start_idx');
            });
        }

        // 2) tenant_availability — never created before the failure.
        if (! Schema::hasTable('tenant_availability')) {
            Schema::create('tenant_availability', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('tenant_user_id')->index();
                $table->unsignedTinyInteger('day_of_week'); // 0=Sun .. 6=Sat (tenant-local)
                $table->string('band', 12);                 // morning | afternoon | evening
                $table->string('preference', 12)->default('available'); // available | prefer | unavailable
                $table->timestamps();

                $table->unique(['tenant_id', 'tenant_user_id', 'day_of_week', 'band'], 'tenant_avail_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_availability');
        if (Schema::hasTable('tenant_time_off_requests')) {
            Schema::table('tenant_time_off_requests', function (Blueprint $table) {
                $table->dropIndex('toff_tenant_user_start_idx');
            });
        }
    }
};

