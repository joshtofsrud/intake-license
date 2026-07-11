<?php
// MARKER-PATCH-629 — unified payment methods. One list governs register
// tenders, customer checkout surfaces, and (later) QB mapping. Built-ins are
// seeded per tenant on first read, importing the legacy per-provider settings
// keys; tenants can add custom manual methods (Zelle, house account, ...).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('method_key', 60);        // cash, card_stripe, venmo, custom_zelle, ...
            $table->string('name', 80);
            $table->string('kind', 12)->default('manual');   // integrated | manual
            $table->boolean('enabled')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->string('mode', 12)->nullable();  // cash_app: manual | stripe
            $table->string('handle', 120)->nullable();       // @venmo / $cashtag / address
            $table->string('instructions', 300)->nullable(); // customer-facing note
            $table->json('surfaces')->nullable();    // {register:{on,hint}, online:{...}, booking:{...}, rental:{...}}
            $table->boolean('link_qr')->default(true);       // generate payment link + QR where supported
            $table->json('qb')->nullable();          // {deposit_account, income_account} — stage 4
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'method_key'], 'tpm_tenant_key_unique');
            $table->index(['tenant_id', 'enabled'], 'tpm_tenant_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_methods');
    }
};

