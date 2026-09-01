<?php

namespace App\Console\Commands;

use App\Services\Platform\GoogleCalendarService;
use Illuminate\Console\Command;

// MARKER-SCHED-GOOGLE
class BookingsSyncGoogle extends Command
{
    protected $signature   = 'bookings:sync-google';
    protected $description = 'Refresh Google Calendar busy time and backfill booking events';

    public function handle(GoogleCalendarService $google): int
    {
        if (! $google->connected()) {
            $this->line('not connected');
            return self::SUCCESS;
        }
        $ok = $google->syncBusy();
        $n  = $google->backfillEvents();
        $this->info(($ok ? 'busy synced' : 'busy sync FAILED') . ", events backfilled: {$n}");
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
