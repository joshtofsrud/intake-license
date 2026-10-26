<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-QUEUE-WATCHDOG: what the queue watchdog remembers between runs.
 * Kept in the database, not the cache: every deploy clears the cache, and a
 * watchdog that forgets on deploy would either miss an outage or cry wolf.
 *
 *   queue_ping_at       when the oldest test job still waiting was queued (moves on once one is run)
 *   queue_heartbeat_at  when a worker last ran one
 *   queue_down_since    set while the queue is down and the alert has gone out
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'queue_ping_at')) $t->timestamp('queue_ping_at')->nullable();
            if (! Schema::hasColumn('platform_settings', 'queue_heartbeat_at')) $t->timestamp('queue_heartbeat_at')->nullable();
            if (! Schema::hasColumn('platform_settings', 'queue_down_since')) $t->timestamp('queue_down_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            foreach (['queue_ping_at', 'queue_heartbeat_at', 'queue_down_since'] as $c) {
                if (Schema::hasColumn('platform_settings', $c)) $t->dropColumn($c);
            }
        });
    }
};
