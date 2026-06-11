<?php
// MARKER-PATCH-225

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_staff_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('user_id')->constrained('tenant_users')->onDelete('cascade');
            $t->string('event', 40);                 // booking.created, payment.failed, …
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();          // deep link into the app
            $t->json('meta')->nullable();
            $t->boolean('is_critical')->default(false);
            $t->dateTime('read_at')->nullable();
            $t->timestamps();
            // The bell query: this user's recent alerts, unread-first.
            $t->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'tsa_user_unread_recent');
        });

        Schema::create('tenant_staff_alert_prefs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('user_id')->constrained('tenant_users')->onDelete('cascade');
            $t->string('event', 40);
            $t->boolean('in_app')->default(true);
            $t->boolean('sms')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'event'], 'tsap_user_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_staff_alert_prefs');
        Schema::dropIfExists('tenant_staff_alerts');
    }
};
