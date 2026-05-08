<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert logo_size_admin / logo_size_booking from small/medium/large enum
 * (stored as string) to integer pixel heights.
 *
 * Existing tenant rows are remapped:
 *   admin   small=22  medium=26  large=36
 *   booking small=22  medium=28  large=44
 *
 * The enum was too coarse — "large" maxed out at 36/44px and several tenants
 * wanted bigger logos. Integer pixels with a slider in the settings UI gives
 * full control with live preview.
 *
 * Ranges enforced in the controller:
 *   admin   16–80
 *   booking 16–120
 */
return new class extends Migration {
    public function up(): void
    {
        // Step 1: add temporary integer columns with a sensible default.
        Schema::table('tenants', function (Blueprint $t) {
            $t->unsignedSmallInteger('logo_size_admin_px')->default(26)->after('logo_size_booking');
            $t->unsignedSmallInteger('logo_size_booking_px')->default(28)->after('logo_size_admin_px');
        });

        // Step 2: copy existing string values into the new columns.
        DB::table('tenants')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $admin = match ($row->logo_size_admin) {
                    'small'  => 22,
                    'large'  => 36,
                    default  => 26, // medium / null / anything unexpected
                };
                $booking = match ($row->logo_size_booking) {
                    'small'  => 22,
                    'large'  => 44,
                    default  => 28,
                };
                DB::table('tenants')->where('id', $row->id)->update([
                    'logo_size_admin_px'   => $admin,
                    'logo_size_booking_px' => $booking,
                ]);
            }
        });

        // Step 3: drop the old string columns.
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['logo_size_admin', 'logo_size_booking']);
        });

        // Step 4: rename the new columns into the canonical names.
        Schema::table('tenants', function (Blueprint $t) {
            $t->renameColumn('logo_size_admin_px',   'logo_size_admin');
            $t->renameColumn('logo_size_booking_px', 'logo_size_booking');
        });
    }

    public function down(): void
    {
        // Reverse: rename back to *_px, add string cols, copy back, drop ints.
        Schema::table('tenants', function (Blueprint $t) {
            $t->renameColumn('logo_size_admin',   'logo_size_admin_px');
            $t->renameColumn('logo_size_booking', 'logo_size_booking_px');
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->string('logo_size_admin', 10)->default('medium')->after('logo_size_booking_px');
            $t->string('logo_size_booking', 10)->default('medium')->after('logo_size_admin');
        });

        DB::table('tenants')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $admin = $row->logo_size_admin_px >= 32 ? 'large'
                       : ($row->logo_size_admin_px <= 24 ? 'small' : 'medium');
                $booking = $row->logo_size_booking_px >= 36 ? 'large'
                         : ($row->logo_size_booking_px <= 24 ? 'small' : 'medium');
                DB::table('tenants')->where('id', $row->id)->update([
                    'logo_size_admin'   => $admin,
                    'logo_size_booking' => $booking,
                ]);
            }
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['logo_size_admin_px', 'logo_size_booking_px']);
        });
    }
};
