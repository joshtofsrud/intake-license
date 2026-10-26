<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POSTMARK-INACTIVE: Intake's copy of Postmark's inactive-recipient
 * list for each marketing stream. Postmark refuses mail to these addresses
 * and sends no webhook when it does, so Intake has to ask for the list.
 *
 *   postmark_suppressions                  one row per stream + address
 *   platform_settings.postmark_inactive_sync  when each stream last synced, how many, any error
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('postmark_suppressions')) {
            Schema::create('postmark_suppressions', function (Blueprint $t) {
                $t->id();
                $t->string('stream', 64);
                $t->string('email', 191);
                $t->string('reason', 32)->nullable();   // HardBounce | SpamComplaint | ManualSuppression
                $t->string('origin', 32)->nullable();   // Recipient | Customer | Admin
                $t->timestamp('suppressed_at')->nullable();
                $t->timestamp('synced_at')->nullable();
                $t->unique(['stream', 'email']);
                $t->index('email');
            });
        }
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'postmark_inactive_sync')) $t->json('postmark_inactive_sync')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postmark_suppressions');
        Schema::table('platform_settings', function (Blueprint $t) {
            if (Schema::hasColumn('platform_settings', 'postmark_inactive_sync')) $t->dropColumn('postmark_inactive_sync');
        });
    }
};
