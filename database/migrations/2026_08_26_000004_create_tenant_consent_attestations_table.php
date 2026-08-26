<?php
// MARKER-EMAIL-CONSENT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_consent_attestations', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            $table->unsignedInteger('contact_count');

            // The EXACT wording the shop agreed to, frozen at confirmation —
            // so the claim can be checked against what was shown at the time,
            // not what the copy says today.
            $table->text('wording');

            // Snapshot of who confirmed (id may outlive the user row).
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->string('confirmed_by_name', 120);
            $table->string('confirmed_by_role', 40)->nullable();
            $table->string('ip', 45)->nullable();

            // Import batch ids, filters used, anything that scopes the claim.
            $table->json('context')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_consent_attestations');
    }
};
