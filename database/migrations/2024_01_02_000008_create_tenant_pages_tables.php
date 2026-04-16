<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Pages (home, contact, about, etc.)
        // ----------------------------------------------------------------
        Schema::create('tenant_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug', 100);    // 'home', 'contact', 'about', etc.
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            // 'home' is the root page; all others appear in nav if is_in_nav = true
            $table->boolean('is_home')->default(false);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_in_nav')->default(true);
            $table->unsignedSmallInteger('nav_order')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_published']);
        });

        // ----------------------------------------------------------------
        // Page sections — one row per draggable block
        // ----------------------------------------------------------------
        Schema::create('tenant_page_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('page_id')->constrained('tenant_pages')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Section types:
             *   nav            — top navigation bar (one per tenant, shared across pages)
             *   hero           — full-width hero with headline + CTA
             *   services       — live services preview grid
             *   text_image     — side-by-side text + image block
             *   cta_banner     — full-width strip with headline + button
             *   image_gallery  — 2-4 column photo grid
             *   contact_form   — name/email/message form
             *   booking_embed  — embeds the booking form inline
             *   footer         — footer with links, social, copyright
             *   custom_html    — raw HTML block (Custom tier only)
             */
            $table->string('section_type', 40);

            /*
             * Content is a JSON object whose schema depends on section_type.
             *
             * Hero example:
             * {
             *   "headline": "Expert Bike Service",
             *   "subheading": "Drop off today, ride tomorrow.",
             *   "bg_type": "image",          // image | color | gradient
             *   "bg_image_url": "...",
             *   "bg_color": "#1a1a1a",
             *   "overlay_opacity": 0.4,
             *   "text_color": "#ffffff",
             *   "cta_primary_label": "Book Now",
             *   "cta_primary_url": "/book",
             *   "cta_secondary_label": "Our Services",
             *   "cta_secondary_url": "#services",
             *   "height": "large"            // small | medium | large | fullscreen
             * }
             *
             * Nav example:
             * {
             *   "show_logo": true,
             *   "show_tagline": false,
             *   "cta_label": "Book Now",
             *   "cta_url": "/book",
             *   "bg_style": "solid"          // solid | transparent | blur
             * }
             */
            $table->json('content');

            // Visual settings that apply to all section types
            $table->string('bg_color', 7)->nullable();   // overrides tenant default
            $table->string('padding')->default('normal'); // none | tight | normal | wide
            $table->boolean('is_visible')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
        });

        // ----------------------------------------------------------------
        // Nav items — managed separately, rendered in the Nav section
        // ----------------------------------------------------------------
        Schema::create('tenant_nav_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('url');
            $table->boolean('is_external')->default(false);
            $table->boolean('open_in_new_tab')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_nav_items');
        Schema::dropIfExists('tenant_page_sections');
        Schema::dropIfExists('tenant_pages');
    }
};
