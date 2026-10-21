<?php
// MARKER-EMAIL-OUTREACH: prospect email outreach. Additive only.
//   sales_email_templates   saved messages with [first_name] [shop] [city] [link]
//   sales_outreach_sends    one row per email sent to a prospect (template, page)
//   sales_prospect_visits   site visits that arrived through a prospect's own link
//   sales_prospects         outreach_code (their link), emailed_at, email_skipped_at, link_clicked_at
//   users.admin_prefs       per-admin remembered choices (template, landing page, filters)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_email_templates')) {
            Schema::create('sales_email_templates', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 120);
                $t->string('subject', 191)->nullable();
                $t->text('body')->nullable();
                $t->string('page', 191)->nullable(); // default landing path, e.g. /bike-shops
                $t->unsignedInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('sales_outreach_sends')) {
            Schema::create('sales_outreach_sends', function (Blueprint $t) {
                $t->id();
                $t->uuid('sales_prospect_id');
                $t->uuid('template_id')->nullable();
                $t->string('page', 191)->nullable();
                $t->string('email', 191);
                $t->string('subject', 191);
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->string('thread_id', 64)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index('sales_prospect_id');
                $t->index('template_id');
                $t->index('created_at');
            });
        }

        if (! Schema::hasTable('sales_prospect_visits')) {
            Schema::create('sales_prospect_visits', function (Blueprint $t) {
                $t->id();
                $t->uuid('sales_prospect_id');
                $t->string('session_id', 64);
                $t->uuid('activity_id')->nullable();
                $t->text('paths')->nullable(); // JSON list of paths, in order
                $t->unsignedInteger('pages')->default(0);
                $t->string('device', 12)->nullable();
                $t->timestamp('first_at')->nullable();
                $t->timestamp('last_at')->nullable();
                $t->index(['sales_prospect_id', 'session_id']);
            });
        }

        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'outreach_code'))    $t->string('outreach_code', 16)->nullable()->unique();
            if (! Schema::hasColumn('sales_prospects', 'emailed_at'))       $t->timestamp('emailed_at')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'email_skipped_at')) $t->timestamp('email_skipped_at')->nullable();
            if (! Schema::hasColumn('sales_prospects', 'link_clicked_at'))  $t->timestamp('link_clicked_at')->nullable();
        });

        // Shops already emailed one at a time from the Prospects card count as
        // emailed, so the new queue doesn't offer them again.
        try {
            \Illuminate\Support\Facades\DB::statement(
                "UPDATE sales_prospects SET emailed_at = (SELECT MAX(a.occurred_at) FROM sales_activities a "
                . "WHERE a.sales_prospect_id = sales_prospects.id AND a.type = 'email' AND a.body LIKE 'Emailed:%') "
                . "WHERE emailed_at IS NULL"
            );
        } catch (\Throwable $e) {
            report($e);
        }

        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'admin_prefs')) $t->text('admin_prefs')->nullable();
        });
    }

    public function down(): void {}
};
