<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PAGE-PUBLISH — when a page went live, so the status box can say
// so instead of implying it.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dateTime('published_at')->nullable()->after('is_published');
        });

        // Anything already flagged published predates this column; stamp it
        // with its last update so the UI doesn't read "never".
        \Illuminate\Support\Facades\DB::table('tenant_pages')
            ->where('is_published', true)
            ->whereNull('published_at')
            ->update(['published_at' => \Illuminate\Support\Facades\DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
