<?php

// MARKER-OLD-SCHOOL-PHOTO

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo paths on a note.
 *
 * A JSON array rather than a child table: a note carries one or two photos,
 * they are never queried across, and they are written and deleted with the
 * note. A table would add a join for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_notes', function (Blueprint $t) {
            $t->json('photos')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_notes', function (Blueprint $t) {
            $t->dropColumn('photos');
        });
    }
};
