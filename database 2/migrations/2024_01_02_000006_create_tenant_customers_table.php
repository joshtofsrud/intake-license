<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 2)->default('US');
            $table->text('notes')->nullable();
            $table->string('stripe_customer_id')->nullable();

            // If this customer was synced from a WP install, track the source
            $table->string('wp_source_url')->nullable();

            $table->timestamps();

            // One email per tenant
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'last_name']);
        });

        Schema::create('tenant_customer_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->string('note', 200);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_notes');
        Schema::dropIfExists('tenant_customers');
    }
};
