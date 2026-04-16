<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master admin `users` table.
 *
 * This backs Filament's admin panel at intake.works/admin. It is separate
 * from `tenant_users`, which holds per-tenant shop staff.
 *
 * NOTE: this migration was missing from the repo prior to this commit —
 * the table had been created ad-hoc on the server. Adding it here so
 * fresh deploys work and `php artisan migrate:fresh` is safe.
 *
 * The `IF NOT EXISTS` guard (Schema::hasTable) means re-running on the
 * existing prod box is a no-op and will not destroy the admin user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            // Table already exists on prod — only add the columns we now rely on
            // if they're missing. This keeps the existing admin row intact.
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'is_admin')) {
                    $table->boolean('is_admin')->default(true)->after('password');
                }
                if (! Schema::hasColumn('users', 'remember_token')) {
                    $table->rememberToken();
                }
            });
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_admin')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // Laravel default companion tables. Check existence so we don't clash
        // with any that were hand-created on the server.
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
