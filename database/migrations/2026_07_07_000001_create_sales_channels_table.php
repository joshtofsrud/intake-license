<?php
// MARKER-CAMPAIGNS-CORE — Campaign/channel definitions per vertical.
// A channel carries the categories, business types, qualification criteria,
// and outreach playbook for one vertical (bike shops, salons, grooming...).
// Prospects belong to a channel; the pipeline mechanics (SalesProspect::STAGES)
// stay system-wide — a channel's `playbook` is display guidance, not new states.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('status', 20)->default('draft');   // active | draft | stub
            $t->json('categories')->nullable();            // ["Sales","Rental","Service"]
            $t->json('business_types')->nullable();        // ["Full-service shop", ...]
            $t->json('criteria')->nullable();              // [{label, note}, ...]
            $t->json('playbook')->nullable();              // ["Prospect","Verify",...] display-only
            $t->string('best_ask', 255)->nullable();
            $t->string('generated_by', 40)->nullable();    // null | 'claude'
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
