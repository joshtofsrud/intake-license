<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class waivers: the studio's text, kept as numbered versions, and who
 * signed which version, how and when. Additive and safe to re-run.
 * MARKER-CLASSES-P9
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_class_waivers')) {
            Schema::create('tenant_class_waivers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->unsignedInteger('version');
                $t->text('body');
                $t->uuid('created_by_user_id')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'version']);
            });
        }
        if (! Schema::hasTable('tenant_class_waiver_signatures')) {
            Schema::create('tenant_class_waiver_signatures', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('customer_id');
                $t->uuid('waiver_id');
                $t->unsignedInteger('version');
                $t->string('signer_name', 160);
                $t->string('method', 12);               // online | paper | kiosk
                $t->string('ip', 45)->nullable();
                $t->uuid('signed_by_user_id')->nullable(); // staff who recorded a paper signature
                $t->timestamp('signed_at');
                $t->timestamps();
                $t->index(['tenant_id', 'customer_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_waiver_signatures');
        Schema::dropIfExists('tenant_class_waivers');
    }
};
