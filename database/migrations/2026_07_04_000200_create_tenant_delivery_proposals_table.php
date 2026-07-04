<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-527 — delivery window proposals: sent when work hits
// Completed; customer confirms via public token page; assume-first
// fallback locks the first window at expires_at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_delivery_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable();
            $table->foreignUuid('customer_id')->nullable();
            $table->string('token', 48)->unique();
            $table->json('windows');                       // [{window_id,date,label,day_label}]
            $table->string('status', 20)->default('pending'); // pending|confirmed|assumed|expired|cancelled
            $table->foreignUuid('confirmed_window_id')->nullable();
            $table->date('confirmed_date')->nullable();
            $table->foreignUuid('delivery_id')->nullable(); // the TenantDelivery created on confirm
            $table->timestamp('expires_at')->nullable();    // UTC instant — assume-first deadline
            $table->timestamp('confirmed_at')->nullable();
            $table->string('sent_channels', 40)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'tdp_tenant_status');
            $table->index(['status', 'expires_at'], 'tdp_assume_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_delivery_proposals');
    }
};
