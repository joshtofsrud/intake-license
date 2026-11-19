<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-BUILDER: brands added from master admin's Add a brand. Each is
 * read by the general store reader with the settings here; no code per brand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_sources', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('label', 120);
            $t->string('base_url', 255);
            $t->string('platform', 24);          // shopify | woocommerce
            $t->json('settings')->nullable();      // size/color option names and the like
            $t->string('created_by', 190)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_sources');
    }
};
