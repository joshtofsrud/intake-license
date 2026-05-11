<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * site_settings: Global brand/marketing settings for intake.works.
 * Single-row table (id=1). Managed via master admin Filament resource.
 *
 * Contains: meta defaults, footer copy, social links, analytics IDs.
 * Brand asset URLs are stored here so they can be overridden without
 * a code deploy (favicon, OG image, logo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $t) {
            $t->id();

            // Meta defaults
            $t->string('default_page_title', 191)->nullable();
            $t->string('default_meta_description', 500)->nullable();
            $t->string('footer_tagline', 255)->nullable();

            // Brand assets — stored as URLs (relative or absolute).
            // When null, the fallback is the shipped public/* asset.
            $t->string('logo_url', 500)->nullable();
            $t->string('favicon_url', 500)->nullable();
            $t->string('og_image_url', 500)->nullable();

            // Social links — null hides the link.
            $t->string('twitter_url', 500)->nullable();
            $t->string('linkedin_url', 500)->nullable();
            $t->string('github_url', 500)->nullable();

            // Analytics IDs.
            $t->string('plausible_domain', 191)->nullable();
            $t->string('gtm_id', 64)->nullable();

            $t->timestamps();
        });

        // Seed the single row with sensible defaults.
        \DB::table('site_settings')->insert([
            'id' => 1,
            'default_page_title' => 'intake — Retail, booking, and classes — built for communication and efficiency.',
            'default_meta_description' => 'For service, retail, fitness, and appointment-based businesses.',
            'footer_tagline' => 'Online booking, work orders, and customer management for service shops.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
