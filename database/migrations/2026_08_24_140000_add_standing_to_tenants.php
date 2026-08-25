<?php
// MARKER-TENANT-STANDING

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'past_due_since')) {
                $table->timestamp('past_due_since')->nullable()->after('subscription_status');
            }
            if (! Schema::hasColumn('tenants', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('past_due_since');
            }
            if (! Schema::hasColumn('tenants', 'suspended_reason')) {
                $table->string('suspended_reason', 255)->nullable()->after('suspended_at');
            }
        });

        // Anything already flagged suspended keeps that meaning, now enforced.
        DB::table('tenants')
            ->where('onboarding_status', 'suspended')
            ->whereNull('suspended_at')
            ->update(['suspended_at' => now(), 'suspended_reason' => 'Suspended before enforcement existed']);

        // A tenant sitting in past_due today has no first-failure date; start
        // their clock now rather than locking them out retroactively.
        DB::table('tenants')
            ->where('subscription_status', 'past_due')
            ->whereNull('past_due_since')
            ->update(['past_due_since' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['past_due_since', 'suspended_at', 'suspended_reason']);
        });
    }
};
