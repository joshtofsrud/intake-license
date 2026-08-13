<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-RECORDS
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('entity');
            $table->timestamp('opened_at')->nullable()->after('invited_at');
            $table->timestamp('portal_seen_at')->nullable()->after('opened_at');
        });

        Schema::create('investor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('visible_to_investor')->default(true);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('investor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('description', 500);
            $table->timestamps();
        });

        Schema::create('raise_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raise_settings');
        Schema::dropIfExists('investor_events');
        Schema::dropIfExists('investor_documents');

        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn(['token', 'opened_at', 'portal_seen_at']);
        });
    }
};
