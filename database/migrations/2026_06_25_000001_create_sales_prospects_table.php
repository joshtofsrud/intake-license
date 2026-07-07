<?php
// MARKER-SALES-CORE — Sales channel: prospect pipeline for the master admin.
// Platform-level (NOT tenant-scoped). One row per shop you might sell Intake to.
// The killer column is tenant_id: once a prospect signs up, link it and the
// dashboard funnel + MRR roll-ups become real instead of guesses.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_prospects', function (Blueprint $t) {
            $t->uuid('id')->primary();

            // Identity / geography
            $t->string('shop', 191);
            $t->string('city', 120)->nullable();
            $t->string('region', 120)->nullable();
            $t->unsignedTinyInteger('loop')->nullable();      // 1..9 trip loop
            $t->string('type', 120)->nullable();              // 'Independent', 'Service', 'E-bike specialist'...

            // Qualification
            $t->char('priority', 1)->default('B');            // A | B | C | D
            $t->boolean('verified')->default(false);
            $t->unsignedSmallInteger('lead_score')->default(0); // 0..110

            // Pipeline
            $t->string('stage', 24)->default('prospect');     // see SalesProspect::STAGES
            $t->date('next_action_on')->nullable();           // drives the "work queue"
            $t->string('next_action', 191)->nullable();
            $t->timestamp('last_contacted_at')->nullable();
            $t->text('lost_reason')->nullable();

            // The bridge: links to a real Tenant once they sign up.
            $t->uuid('tenant_id')->nullable();

            // Contact (filled as you work the list)
            $t->string('owner_contact', 191)->nullable();
            $t->string('phone', 64)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('website', 255)->nullable();

            // Provenance / context
            $t->string('best_ask', 191)->nullable();
            $t->string('source', 120)->nullable();
            $t->string('source_url', 512)->nullable();
            $t->text('notes')->nullable();

            // Map
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();

            $t->timestamps();

            $t->index(['stage', 'priority']);
            $t->index(['loop', 'priority']);
            $t->index('next_action_on');
            $t->index('tenant_id');
            $t->unique(['shop', 'city']);

            $t->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('sales_prospects'); }
};
