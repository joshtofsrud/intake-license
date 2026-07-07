<?php
// MARKER-REPPANEL-INVITE — tokenized setup-link invites (Team & access pattern).
// Token is stored sha256-hashed; the raw token only exists in the email link.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_reps', 'invite_token')) {
                $t->string('invite_token', 64)->nullable()->index()->after('user_id');
            }
            if (! Schema::hasColumn('sales_reps', 'invited_at')) {
                $t->timestamp('invited_at')->nullable()->after('invite_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $t) {
            foreach (['invite_token', 'invited_at'] as $col) {
                if (Schema::hasColumn('sales_reps', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
