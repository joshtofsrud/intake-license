<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LIVE-VIEW: one row per live view request. The row is the record of
 * who asked, who allowed it and when it ended. What was on screen is never
 * saved here; it passes through the cache and expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('live_view_sessions')) return;

        Schema::create('live_view_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('tenant_user_id')->nullable();          // asked of one person; null = anyone signed in
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 120)->default('');
            $table->string('status', 16)->default('requested');  // requested|active|ended|declined|expired
            $table->uuid('accepted_by_user_id')->nullable();
            $table->string('accepted_by_name', 120)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 60)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_view_sessions');
    }
};
