<?php
// MARKER-PATCH-HLC4A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time series of distributor availability per variant, stamped by the tier-2
 * sync each time it reads live availability. Feeds the future sell-through
 * velocity opportunity page (qty dropping across snapshots = moving). Cheap to
 * start accumulating now so history exists when we build the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_availability_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable();
            $table->string('distributor_code', 32);
            $table->string('distributor_variant_no', 64);
            $table->uuid('distributor_catalog_id')->nullable();
            $table->integer('avail')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['distributor_code', 'distributor_variant_no', 'checked_at'], 'das_variant_time_idx');
            $table->index(['tenant_id', 'checked_at'], 'das_tenant_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_availability_snapshots');
    }
};
