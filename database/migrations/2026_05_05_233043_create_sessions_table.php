<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            // Session ID is a 40-char Laravel-generated string.
            $table->string('id')->primary();

            // user_id stored as string to support both:
            //   - platform users (BIGINT, stringified)
            //   - tenant users (UUID, 36 chars)
            // No FK constraint since the column points at two possible tables
            // depending on which guard authenticated the user.
            $table->string('user_id', 36)->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');

            // Unix timestamp. Indexed for the GC sweep that deletes
            // sessions older than session.lifetime.
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
