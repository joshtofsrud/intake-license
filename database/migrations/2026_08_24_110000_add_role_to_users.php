<?php
// MARKER-ADMIN-ROLES — staff roles + suspension on platform users.
// Backfill: is_admin=1 → admin, rep-linked users → rep, ADMIN_EMAIL → owner.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->nullable()->after('is_admin');
            }
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('role');
            }
        });

        DB::table('users')->where('is_admin', true)->whereNull('role')->update(['role' => 'admin']);

        if (Schema::hasTable('sales_reps')) {
            $repUserIds = DB::table('sales_reps')->whereNotNull('user_id')->pluck('user_id');
            if ($repUserIds->isNotEmpty()) {
                DB::table('users')->whereIn('id', $repUserIds)->update(['role' => 'rep']);
            }
        }

        $ownerEmail = strtolower((string) config('intake.admin_email', ''));
        if ($ownerEmail !== '') {
            DB::table('users')->whereRaw('LOWER(email) = ?', [$ownerEmail])->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'suspended_at']);
        });
    }
};
