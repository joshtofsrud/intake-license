<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SHARED-COMMIT — a record made by someone holding the shared link,
// rather than one Josh invited. Same shape, different provenance, and the
// difference has to be visible before anything is signed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->boolean('self_declared')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn('self_declared');
        });
    }
};
