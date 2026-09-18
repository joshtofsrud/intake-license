<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-PLATFORM-INBOUND — replies come back, and the sender stops being duplicated. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            // These duplicated mail_from_address / mail_from_name, which the
            // Platform email page has always owned. One address, one place.
            if (Schema::hasColumn('platform_settings', 'platform_from_name')) {
                $t->dropColumn('platform_from_name');
            }
            if (Schema::hasColumn('platform_settings', 'platform_from_email')) {
                $t->dropColumn('platform_from_email');
            }
        });

        Schema::table('platform_inbox_messages', function (Blueprint $t) {
            // The token that makes a reply findable. Unique so a reply can only
            // ever land on one conversation.
            $t->string('inbound_token', 48)->nullable()->unique();
            $t->uuid('campaign_id')->nullable()->index();
            $t->timestamp('last_message_at')->nullable();
        });

        Schema::create('platform_inbox_replies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('message_id')->index();
            // in = they wrote to us, out = we wrote to them
            $t->string('direction', 4);
            $t->string('from_email', 191)->nullable();
            $t->text('body')->nullable();
            $t->string('external_id', 191)->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_inbox_replies');

        Schema::table('platform_inbox_messages', function (Blueprint $t) {
            $t->dropColumn(['inbound_token', 'campaign_id', 'last_message_at']);
        });

        Schema::table('platform_settings', function (Blueprint $t) {
            $t->string('platform_from_name', 120)->nullable();
            $t->string('platform_from_email', 191)->nullable();
        });
    }
};
