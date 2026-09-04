<?php
// MARKER-SUPPORT-EMAIL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('support_email', 191)->nullable()->after('mail_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('support_email'));
    }
};
