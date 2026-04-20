<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            // Structured block content. NULL for legacy campaigns that use body_html directly.
            $table->json('blocks')->nullable()->after('body_text');
        });

        Schema::table('tenant_email_templates', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
        Schema::table('tenant_email_templates', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
    }
};
