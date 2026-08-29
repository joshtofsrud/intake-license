<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SIGNING-SEND — which Dropbox Sign request belongs to which investor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->string('signature_request_id')->nullable()->index()->after('token');
            $table->timestamp('safe_sent_at')->nullable()->after('signature_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn(['signature_request_id', 'safe_sent_at']);
        });
    }
};
