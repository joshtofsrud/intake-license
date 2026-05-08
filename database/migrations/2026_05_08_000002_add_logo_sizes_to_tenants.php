<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo display size, per surface.
 *
 * Two columns so tenants can size their booking-page logo big and proud
 * while keeping the admin sidebar tight, or vice versa. Three-value enum
 * (small / medium / large) — no slider, no pixel control. Medium maps to
 * the current hardcoded values so existing tenants see no change.
 *
 * Pixel mapping (set in CSS via body data-attributes):
 *   admin   small=22px medium=26px large=36px
 *   booking small=22px medium=28px large=44px
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('logo_size_admin', 10)->default('medium')->after('logo_light_url');
            $t->string('logo_size_booking', 10)->default('medium')->after('logo_size_admin');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['logo_size_admin', 'logo_size_booking']);
        });
    }
};
