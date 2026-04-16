<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {

            // ----------------------------------------------------------------
            // Branding
            // ----------------------------------------------------------------
            $table->string('logo_url')->nullable()->after('name');
            $table->string('favicon_url')->nullable()->after('logo_url');
            $table->string('accent_color', 7)->default('#BEF264')->after('favicon_url');
            $table->string('text_color', 7)->default('#111111')->after('accent_color');
            $table->string('bg_color', 7)->default('#ffffff')->after('text_color');
            $table->string('font_heading')->default('Inter')->after('bg_color');
            $table->string('font_body')->default('Inter')->after('font_heading');
            $table->string('tagline')->nullable()->after('font_body');

            // ----------------------------------------------------------------
            // Email config
            // ----------------------------------------------------------------
            // from_name / from_email used as the sender for all tenant emails.
            // reply_to is optional; falls back to from_email.
            $table->string('email_from_name')->nullable()->after('tagline');
            $table->string('email_from_address')->nullable()->after('email_from_name');
            $table->string('email_reply_to')->nullable()->after('email_from_address');

            // ----------------------------------------------------------------
            // SMS config (Twilio — architecture ready, wired up later)
            // ----------------------------------------------------------------
            $table->boolean('sms_enabled')->default(false)->after('email_reply_to');
            $table->string('sms_from_number')->nullable()->after('sms_enabled');
            $table->string('twilio_account_sid')->nullable()->after('sms_from_number');
            $table->string('twilio_auth_token')->nullable()->after('twilio_account_sid');

            // ----------------------------------------------------------------
            // Onboarding
            // ----------------------------------------------------------------
            $table->enum('onboarding_status', [
                'pending',      // just signed up
                'in_progress',  // your team is setting them up
                'complete',     // fully onboarded
            ])->default('pending')->after('twilio_auth_token');
            $table->timestamp('onboarded_at')->nullable()->after('onboarding_status');

            // ----------------------------------------------------------------
            // Notification email for new bookings (shop owner inbox)
            // ----------------------------------------------------------------
            $table->string('notification_email')->nullable()->after('onboarded_at');

            // ----------------------------------------------------------------
            // Currency
            // ----------------------------------------------------------------
            $table->string('currency', 3)->default('USD')->after('notification_email');
            $table->string('currency_symbol', 5)->default('$')->after('currency');

            // ----------------------------------------------------------------
            // Booking settings
            // ----------------------------------------------------------------
            $table->unsignedSmallInteger('booking_window_days')->default(60)->after('currency_symbol');
            $table->unsignedSmallInteger('min_notice_hours')->default(24)->after('booking_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'logo_url', 'favicon_url', 'accent_color', 'text_color', 'bg_color',
                'font_heading', 'font_body', 'tagline',
                'email_from_name', 'email_from_address', 'email_reply_to',
                'sms_enabled', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token',
                'onboarding_status', 'onboarded_at',
                'notification_email', 'currency', 'currency_symbol',
                'booking_window_days', 'min_notice_hours',
            ]);
        });
    }
};
