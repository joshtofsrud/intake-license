<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SITE-HEALTH: what a shop should fix so more people find it.
 *
 *  tenant_site_health        one row a shop: the last check, its place in a running one, the score
 *  tenant_site_issues        one row a thing to fix (or fixed, or set aside by the shop)
 *  tenant_google_connections one row a shop: its Google sign-in, what was picked, the last numbers read
 *
 * Additive throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_site_health')) {
            Schema::create('tenant_site_health', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id')->unique();
                $t->unsignedTinyInteger('score')->nullable();
                $t->unsignedInteger('checked')->default(0);      // pages and items looked at in the last finished check
                $t->unsignedInteger('weight_total')->default(0); // what a perfect score is out of
                $t->json('state')->nullable();                   // a running check: place, counts, stopped
                $t->json('basics')->nullable();
                $t->json('history')->nullable();                 // [date, score] of past checks
                $t->dateTime('checked_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_site_issues')) {
            Schema::create('tenant_site_issues', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->string('fingerprint', 64);
                $t->string('grp', 12);                 // text | media | business | links
                $t->string('code', 40);
                $t->string('kind', 40);                // the short label on the row
                $t->string('subject_type', 12);        // page | items | site
                $t->string('subject_id', 64)->nullable();
                $t->string('label', 190);
                $t->string('path', 255)->nullable();
                $t->string('problem', 255);
                $t->string('impact', 6);               // high | medium | low
                $t->unsignedInteger('weight')->default(1);
                $t->json('detail')->nullable();
                $t->string('status', 10)->default('open'); // open | fixed | hidden
                $t->json('undo')->nullable();
                $t->unsignedInteger('run')->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'fingerprint'], 'tsi_fp_unique');
                $t->index(['tenant_id', 'status'], 'tsi_status_idx');
            });
        }

        if (! Schema::hasTable('tenant_google_connections')) {
            Schema::create('tenant_google_connections', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id')->unique();
                $t->string('email', 190)->nullable();
                $t->text('access_token')->nullable();
                $t->text('refresh_token')->nullable();
                $t->dateTime('token_expires_at')->nullable();
                $t->string('scopes', 600)->nullable();
                $t->json('picks')->nullable();     // which property / account / location the shop uses
                $t->json('options')->nullable();   // what the sign-in can see, to pick from
                $t->json('data')->nullable();      // the last numbers read, by source
                $t->json('state')->nullable();     // a running read, and each source's last error
                $t->uuid('connected_by')->nullable();
                $t->dateTime('connected_at')->nullable();
                $t->dateTime('synced_at')->nullable();
                $t->timestamps();
            });
        }

        // master admin's switches: can shops connect Google, and are reviews on (Google approves that separately)
        if (Schema::hasTable('platform_settings') && ! Schema::hasColumn('platform_settings', 'site_health_settings')) {
            Schema::table('platform_settings', fn (Blueprint $t) => $t->json('site_health_settings')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_google_connections');
        Schema::dropIfExists('tenant_site_issues');
        Schema::dropIfExists('tenant_site_health');
    }
};
