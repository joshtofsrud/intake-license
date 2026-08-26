<?php
// MARKER-EMAIL-CONSENT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            // Marketing consent, per customer per tenant. Three effective states:
            //   consent_at set, opt_out_at null  -> mailable
            //   consent_at null                  -> unconfirmed (imports, walk-ins)
            //   opt_out_at set                   -> unsubscribed (marketing only)
            $table->timestamp('email_marketing_consent_at')->nullable()->after('sms_consent_source');
            // 'booking_form','checkout','signup','portal','attestation','permission_email'
            $table->string('email_marketing_consent_source', 40)->nullable()->after('email_marketing_consent_at');
            $table->timestamp('email_marketing_opt_out_at')->nullable()->after('email_marketing_consent_source');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->dropColumn([
                'email_marketing_consent_at',
                'email_marketing_consent_source',
                'email_marketing_opt_out_at',
            ]);
        });
    }
};
