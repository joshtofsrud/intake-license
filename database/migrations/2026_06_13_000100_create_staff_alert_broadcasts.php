<?php
// MARKER-PATCH-279

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_staff_alert_broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('created_by')->nullable()->constrained('tenant_users')->onDelete('set null');
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('priority', 10)->default('low');   // 'high' | 'low'
            $t->json('audience')->nullable();             // null = all active staff
            $t->boolean('show_banner')->default(true);
            $t->boolean('send_email')->default(false);
            $t->dateTime('expires_at')->nullable();       // banner auto-expiry; null = until dismissed
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'is_active', 'expires_at'], 'tsab_active_window');
        });

        Schema::create('tenant_staff_broadcast_dismissals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('broadcast_id')->constrained('tenant_staff_alert_broadcasts')->onDelete('cascade');
            $t->foreignUuid('user_id')->constrained('tenant_users')->onDelete('cascade');
            $t->dateTime('dismissed_at');
            $t->unique(['broadcast_id', 'user_id'], 'tsbd_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_staff_broadcast_dismissals');
        Schema::dropIfExists('tenant_staff_alert_broadcasts');
    }
};
