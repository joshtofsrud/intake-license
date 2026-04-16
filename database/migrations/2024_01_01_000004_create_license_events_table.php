<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_id')->constrained()->cascadeOnDelete();

            /*
             * Event types:
             *   activated      — new site activated
             *   deactivated    — site removed activation
             *   checked        — periodic validity check
             *   suspended      — admin suspended the license
             *   reinstated     — admin lifted suspension
             *   expired        — passed expires_at
             *   cancelled      — admin/billing cancelled
             *   renewed        — billing renewed (set by intake.works webhook)
             *   key_reset      — license key was regenerated
             */
            $table->string('event_type', 40)->index();

            $table->string('site_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('note')->nullable();

            // Snapshot of plugin/wp version at time of event
            $table->string('plugin_version', 20)->nullable();
            $table->string('wp_version', 20)->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
    }
};
