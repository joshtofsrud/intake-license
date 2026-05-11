<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_class_templates', function (Blueprint $table) {
            // Permanent note attached to the class definition. Shown to staff
            // on every session's roster. Will be included in customer booking
            // confirmation emails when those ship (currently a known gap —
            // class registrations don't send emails yet).
            $table->text('class_notes')->nullable()->after('description');
        });

        Schema::table('tenant_class_sessions', function (Blueprint $table) {
            // Per-session-only roster note. Independent of the template's
            // class_notes — both can be set, both surface. Distinct from
            // `notes`, which remains the staff-only operational note shown
            // in the session info sidebar.
            $table->text('session_notes_override')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_class_templates', function (Blueprint $table) {
            $table->dropColumn('class_notes');
        });
        Schema::table('tenant_class_sessions', function (Blueprint $table) {
            $table->dropColumn('session_notes_override');
        });
    }
};
