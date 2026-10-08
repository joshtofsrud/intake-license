<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-IG-QUEUE: the Instagram follow queue (master admin › Sales ›
 * Instagram follows). A person opens each profile and taps Follow
 * themselves; these columns only record that it happened. Additive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'ig_followed_at')) { $t->timestamp('ig_followed_at')->nullable(); }
            if (! Schema::hasColumn('sales_prospects', 'ig_skipped_at'))  { $t->timestamp('ig_skipped_at')->nullable(); }
        });
    }

    public function down(): void
    {
        // additive: columns stay.
    }
};
