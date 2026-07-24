<?php

// MARKER-PLATFORM-MAIL — single-row platform settings, same shape as
// billing_settings. First occupant: the platform email sender, which was
// falling through to Laravel's framework default (hello@example.com)
// because no config/mail.php is published and no MAIL_FROM_* env is set.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('mail_from_address')->nullable();
            $t->string('mail_from_name')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
