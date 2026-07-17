<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistOffer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireWaitlistEntries extends Command
{
    protected $signature   = 'waitlist:expire';
    protected $description = 'Mark waitlist entries past their date range as expired, and pending offers past slot time.';

    public function handle(): int
    {
        // MARKER-TZ-WAVE3 — date_range_end is a tenant-local business date;
        // expire per tenant against that tenant's local today, not UTC's.
        $entryCount = 0;
        foreach (\App\Console\Commands\MembershipsTickCommand::tenantLocalDates() as $tenantId => $localToday) {
            $entryCount += TenantWaitlistEntry::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereDate('date_range_end', '<', $localToday)
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }

        $offerCount = TenantWaitlistOffer::whereIn('status', ['pending', 'viewed'])
            ->where('offer_expires_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $this->info("Expired {$entryCount} entries and {$offerCount} offers.");
        return self::SUCCESS;
    }
}
