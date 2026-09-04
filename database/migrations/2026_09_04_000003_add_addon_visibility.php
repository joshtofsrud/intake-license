<?php
// MARKER-ADDON-VISIBILITY

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->string('visibility', 16)->default('self_serve')->after('is_self_serve');
        });

        // Seed from what is true today so nothing changes on deploy: anything a
        // shop could switch on stays self-serve, everything else becomes hidden
        // — which is what it already was in practice. Turning those into "ask"
        // is a decision to make per add-on, not one to make for you in a
        // migration.
        DB::table('addons')->where('is_self_serve', true)->update(['visibility' => 'self_serve']);
        DB::table('addons')->where('is_self_serve', false)->update(['visibility' => 'hidden']);
    }

    public function down(): void
    {
        Schema::table('addons', fn (Blueprint $t) => $t->dropColumn('visibility'));
    }
};
