<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-ITEM-DETAILS: brand, category, supplier, cost and weight sent per linked item,
 * and the last reason they could not be. MARKER-POS-CUSTOMERS: Intake customer ↔ Connected
 * POS customer, so one person is never created twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pos_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_items', 'details_sent_at')) { $t->timestamp('details_sent_at')->nullable(); }
            if (! Schema::hasColumn('tenant_pos_items', 'details_error')) { $t->string('details_error', 500)->nullable(); }
        });
        if (! Schema::hasTable('tenant_pos_customers')) {
            Schema::create('tenant_pos_customers', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id')->index();
                $t->uuid('customer_id')->nullable();
                $t->string('email', 190)->nullable();
                $t->string('external_id', 100);
                $t->timestamps();
                $t->unique(['tenant_id', 'customer_id']);
                $t->index(['tenant_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pos_customers');
        Schema::table('tenant_pos_items', function (Blueprint $t) {
            $t->dropColumn(['details_sent_at', 'details_error']);
        });
    }
};
