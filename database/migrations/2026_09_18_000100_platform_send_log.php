<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-PLATFORM-SENDLOG — what went out, and who can't be mailed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_email_sends', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // template | test | reply | alert — campaigns keep their own table
            $t->string('kind', 16)->index();
            $t->string('template_key', 48)->nullable()->index();
            $t->string('email', 191)->index();
            $t->string('subject', 200)->nullable();
            $t->uuid('tenant_id')->nullable()->index();
            // queued | sent | failed
            $t->string('status', 16)->default('sent');
            $t->text('error')->nullable();
            $t->string('message_id', 191)->nullable()->index();
            $t->timestamps();

            $t->index(['created_at']);
        });

        // The existing opt-out table becomes the single suppression list, so
        // nothing has to be migrated and the unsubscribe link keeps working
        // exactly as it does now.
        Schema::table('platform_email_optouts', function (Blueprint $t) {
            // unsubscribe | bounce | complaint
            $t->string('kind', 16)->default('unsubscribe')->after('email')->index();
            $t->text('detail')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('platform_email_optouts', function (Blueprint $t) {
            $t->dropColumn(['kind', 'detail']);
        });

        Schema::dropIfExists('platform_email_sends');
    }
};
