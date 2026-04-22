<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create quiz_completions (analytics) and tenant_tags (classification).
 *
 * quiz_completions logs every completed plan-quiz submission for the master admin
 * analytics dashboard. One row per completion. If the same session completes
 * multiple times, we store each run — people exploring different scenarios is signal.
 *
 * tenant_tags is a many-to-many style table. Tags come from a fixed vocabulary
 * (app-level enum), not user-defined. Multiple tags per tenant allowed.
 *
 * Fixed tag vocabulary (enforced at app level, not DB):
 *   - quiz-signup       : applied on all quiz-sourced signups
 *   - enterprise-quiz   : quiz flagged them as enterprise-scale
 *   - high-volume       : quiz Q1 answered 200+
 *   - multi-location    : quiz Q3 answered 2+
 *   - needs-setup-help  : quiz Q5 answered "someone to do it for me"
 *   - manual-followup   : master admin flagged for outreach
 *   - churn-risk        : master admin flagged, leaving soon
 *   - vip               : master admin flagged, priority handling
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_completions', function (Blueprint $t) {
            $t->id();

            // Session identifier (client-generated UUID stored in sessionStorage).
            // Lets us tie quiz completion to eventual signup without needing auth.
            $t->string('session_id', 64)->index();

            // The 5 answers, stored as JSON for forward-compatibility if we add/change questions.
            // Expected keys: volume, website, locations, branding, setup
            $t->json('answers');

            // Final recommendation: starter | branded | scale
            $t->string('recommendation', 16);

            // Tags this quiz would apply on signup (array of strings).
            $t->json('tags_applied')->nullable();

            // Conversion tracking. NULL until a tenant signs up with this session_id.
            $t->timestamp('converted_to_signup_at')->nullable();
            $t->uuid('converted_tenant_id')->nullable();

            // Lightweight request context for debugging (no PII).
            $t->string('user_agent', 255)->nullable();
            $t->string('referrer', 255)->nullable();

            $t->timestamps();

            $t->index('recommendation');
            $t->index('converted_to_signup_at');
            $t->index('created_at');
        });

        Schema::create('tenant_tags', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id');
            $t->string('tag', 32);

            // Where the tag came from: quiz, manual, import, system.
            $t->string('source', 16)->default('manual');

            // Optional reference to the admin who added it (null if system-assigned).
            $t->unsignedBigInteger('created_by_id')->nullable();

            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $t->unique(['tenant_id', 'tag']); // same tenant can't have same tag twice
            $t->index('tag');
            $t->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_tags');
        Schema::dropIfExists('quiz_completions');
    }
};
