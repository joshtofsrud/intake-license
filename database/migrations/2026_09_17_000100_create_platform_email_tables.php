<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-PLATFORM-EMAIL — Intake's own marketing email. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audiences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            // tenants | tenant_owners | prospects | wrote_in | reps
            // NOT shop customers. There is no value here that reaches them.
            $t->string('source', 32);
            $t->json('rules')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);
            $t->string('subject', 200)->nullable();
            $t->string('preheader', 200)->nullable();
            $t->string('from_name', 120)->nullable();
            $t->string('from_email', 191)->nullable();
            $t->uuid('audience_id')->nullable()->index();
            $t->json('blocks')->nullable();
            // draft | scheduled | sending | sent | cancelled
            $t->string('status', 16)->default('draft')->index();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedInteger('total_recipients')->default(0);
            $t->unsignedInteger('total_sent')->default(0);
            $t->unsignedInteger('total_opened')->default(0);
            $t->unsignedInteger('total_clicked')->default(0);
            $t->timestamps();
        });

        Schema::create('platform_campaign_sends', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id')->index();
            $t->string('email', 191);
            $t->string('name', 160)->nullable();
            $t->string('source_type', 32)->nullable();
            $t->string('source_id', 64)->nullable();
            // pending | sent | skipped | failed
            $t->string('status', 16)->default('pending')->index();
            $t->string('skip_reason', 64)->nullable();
            $t->text('error')->nullable();
            $t->string('tracking_token', 64)->nullable()->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->timestamps();

            $t->unique(['campaign_id', 'email']);
        });

        // Keyed by EMAIL, not by source row: the same person can be a prospect
        // and a tenant owner, and one unsubscribe has to stop both.
        Schema::create('platform_email_optouts', function (Blueprint $t) {
            $t->string('email', 191)->primary();
            $t->string('reason', 64)->nullable();
            $t->string('source', 32)->nullable();
            $t->timestamps();
        });

        Schema::table('platform_settings', function (Blueprint $t) {
            // Deliberately NOT email_broadcast_stream — that one is the tenants'.
            $t->string('platform_broadcast_stream', 64)->nullable();
            $t->string('platform_from_name', 120)->nullable();
            $t->string('platform_from_email', 191)->nullable();
            $t->string('platform_postal_address', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn([
                'platform_broadcast_stream', 'platform_from_name',
                'platform_from_email', 'platform_postal_address',
            ]);
        });
        Schema::dropIfExists('platform_email_optouts');
        Schema::dropIfExists('platform_campaign_sends');
        Schema::dropIfExists('platform_campaigns');
        Schema::dropIfExists('platform_audiences');
    }
};
