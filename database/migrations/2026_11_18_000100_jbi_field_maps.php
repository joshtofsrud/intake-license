<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Seeds JBI's field map so the first sync has one (the sync refuses to run
 * without). Additive: rows already edited in master admin are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\JbiFieldMapSeeder())->run();
    }

    public function down(): void
    {
        // field maps are master-admin data; nothing is removed
    }
};
