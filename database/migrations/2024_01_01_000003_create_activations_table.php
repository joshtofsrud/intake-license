<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Null for free installs; set for premium activations
            $table->foreignUuid('license_id')->nullable()->constrained()->nullOnDelete();

            // Canonical site identifier — we normalise the URL on ingest
            $table->string('site_url')->index();
            $table->string('site_name')->nullable();

            // Version telemetry — helpful for support and knowing when to drop old compat
            $table->string('wp_version', 20)->nullable();
            $table->string('plugin_version', 20)->nullable();

            // Free vs premium — duplicates the license_id null check but makes queries simpler
            $table->enum('type', ['free', 'premium'])->default('free')->index();

            // IP of the pinging site (for rough geo, not enforcement)
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('activated_at')->useCurrent();

            // One row per site URL (free installs) or per site+license combo (premium)
            $table->unique(['license_id', 'site_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activations');
    }
};
