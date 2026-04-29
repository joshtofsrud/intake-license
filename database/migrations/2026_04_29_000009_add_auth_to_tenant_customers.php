<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->rememberToken()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('remember_token');
            $table->string('password_reset_token')->nullable()->after('email_verified_at');
            $table->timestamp('password_reset_sent_at')->nullable()->after('password_reset_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->dropColumn([
                'password',
                'remember_token',
                'email_verified_at',
                'password_reset_token',
                'password_reset_sent_at',
            ]);
        });
    }
};
