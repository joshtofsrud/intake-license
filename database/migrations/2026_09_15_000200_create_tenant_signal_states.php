<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-TENANT-SIGNALS — is each signal currently active, per tenant. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_signal_states', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->string('signal', 40);
            $t->boolean('active')->default(false);
            $t->timestamp('changed_at')->nullable();
            $t->json('detail')->nullable();
            $t->timestamps();

            $t->primary(['tenant_id', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_signal_states');
    }
};
