<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MARKER-INBOX — the master-admin inbox: messages and alerts, one table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_inbox_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();

            // contact | access_request | support   (messages — someone is waiting)
            // alert                                (the platform telling you something)
            $t->string('kind', 24)->index();
            // new | read | archived | spam
            $t->string('status', 16)->default('new')->index();

            $t->uuid('tenant_id')->nullable()->index();

            $t->string('name', 160)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('company', 160)->nullable();
            $t->string('subject', 200)->nullable();
            $t->text('body')->nullable();
            $t->string('source_url', 500)->nullable();

            // Alerts carry the same ref the email and log line carry.
            $t->string('ref_id', 40)->nullable()->index();
            $t->json('meta')->nullable();

            $t->timestamp('read_at')->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->text('reply_body')->nullable();
            $t->string('ip', 64)->nullable();

            $t->timestamps();

            $t->index(['status', 'created_at']);
        });

        // Every contact_submitted event on the platform tenant to date was a
        // bot — the server log holds every valid submission since Sep 4 and
        // all of them are "Robertjoype" / "Robertreunc". The funnel and the
        // "Got in touch" card start honest.
        $platformId = DB::table('tenants')->where('is_platform', true)->value('id');
        if ($platformId) {
            DB::table('tenant_funnel_events')
                ->where('tenant_id', $platformId)
                ->where('event_type', 'contact_submitted')
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_inbox_messages');
    }
};
