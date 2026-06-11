<?php
// MARKER-PATCH-224

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            // Twilio IncomingPhoneNumber SID for Intake-managed numbers —
            // required to release or reconfigure the number later. NULL for
            // BYO tenants (their number lives on their own account).
            $t->string('twilio_number_sid', 64)->nullable()->after('twilio_auth_token');

            // Inbound tenant-resolution keys on sms_from_number; a collision
            // would silently misroute customer texts. Nullable-unique: many
            // NULLs allowed, duplicates forbidden.
            $t->unique('sms_from_number', 'tenants_sms_from_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropUnique('tenants_sms_from_number_unique');
            $t->dropColumn('twilio_number_sid');
        });
    }
};
