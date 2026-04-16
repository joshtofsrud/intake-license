<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

            // The license key the plugin sends with every request
            $table->string('license_key', 64)->unique()->index();

            // Tier: 'premium' for now; expandable later
            $table->enum('tier', ['premium'])->default('premium');

            // Status lifecycle: active → suspended | expired | cancelled
            $table->enum('status', ['active', 'suspended', 'expired', 'cancelled'])
                  ->default('active')
                  ->index();

            // How many WP sites can activate this key (null = unlimited)
            $table->unsignedSmallInteger('site_limit')->default(1);

            // JSON feature flags — override plan defaults for custom deals
            // e.g. {"saas_access": true, "white_label": true}
            $table->json('feature_flags')->nullable();

            // Whether this license grants access to the hosted SaaS platform
            $table->boolean('saas_access')->default(false);

            // Optional expiry — null means perpetual (pay-once) or managed externally
            $table->timestamp('expires_at')->nullable()->index();

            // Internal notes (admin only)
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
