<?php
// MARKER-SMS-METER

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            // One ledger, two channels. Existing rows are all email.
            $table->string('channel', 8)->default('email')->after('kind')->index();
            // Segments carry the arithmetic: an email is always 1, a text may
            // be several, and the row's rate is per segment.
            $table->unsignedSmallInteger('segments')->default(1)->after('rate');
            $table->string('to_phone', 32)->nullable()->after('to_email');
        });

        // to_email was NOT NULL; a text has no email address.
        DB::statement('ALTER TABLE tenant_email_ledger MODIFY to_email VARCHAR(255) NULL');

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('sms_rate', 8, 5)->default(0.014)->after('email_rate');
            // A picture message costs several times a text; carriers do not
            // express it in segments, so it is a multiplier on the same rate.
            $table->unsignedTinyInteger('mms_multiplier')->default(3)->after('sms_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->dropColumn(['channel', 'segments', 'to_phone']);
        });
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['sms_rate', 'mms_multiplier']);
        });
    }
};
