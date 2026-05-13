<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * theme_settings_audits — log of every publish or revert action.
 * Used by the master admin to answer "why does the sidebar look different
 * today?" months after a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings_audits', function (Blueprint $t) {
            $t->id();
            $t->string('theme', 8);
            $t->string('token_key', 64);
            $t->string('old_value', 255)->nullable();
            $t->string('new_value', 255)->nullable();
            $t->string('action', 16);   // 'publish' or 'revert'
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['theme', 'created_at']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings_audits');
    }
};
