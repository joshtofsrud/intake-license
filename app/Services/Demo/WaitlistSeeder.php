<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Models\Tenant\TenantWaitlistSettings;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds waitlist data: settings, entries in various states, and a few
 * past offers to show the system has history.
 */
class WaitlistSeeder
{
    public function __construct(private readonly Closure $logger) {}

    private function log(string $msg): void { ($this->logger)($msg); }

    public function seed(Tenant $tenant, array $customers, array $servicesBySlug): void
    {
        // Settings — enabled, sensible defaults
        TenantWaitlistSettings::create([
            'tenant_id'                    => $tenant->id,
            'enabled'                      => true,
            'similar_match_rule'           => 'by_category',
            'exclude_first_time_customers' => false,
            'include_cancellations'        => true,
            'include_new_openings'         => false,
            'include_manual_offers'        => true,
            'notify_sms'                   => true,
            'notify_email'                 => true,
            'max_entries_per_customer'     => 3,
            'offer_copy_override'          => null,
        ]);

        // Also flip the has_waitlist_addon flag on the tenant
        $tenant->update(['has_waitlist_addon' => true]);

        $services = array_values($servicesBySlug);
        if (empty($services) || empty($customers)) {
            $this->log("  Waitlist: skipped (no services or customers).");
            return;
        }

        $entriesCreated = 0;
        $offersCreated  = 0;

        // ---- 8 active entries ----
        for ($i = 0; $i < 8; $i++) {
            $customer = $customers[array_rand($customers)];
            $service  = $services[array_rand($services)];
            $entry = $this->createEntry($tenant, $customer, $service, 'active');
            $entriesCreated++;

            // ~30% of active entries have a pending or viewed offer attached
            if (random_int(1, 100) <= 30) {
                $this->createOffer($tenant, $entry, random_int(1, 100) <= 50 ? 'pending' : 'viewed');
                $offersCreated++;
            }
        }

        // ---- 2 fulfilled entries (customer accepted an offer) ----
        for ($i = 0; $i < 2; $i++) {
            $customer = $customers[array_rand($customers)];
            $service  = $services[array_rand($services)];
            $entry = $this->createEntry($tenant, $customer, $service, 'fulfilled');
            $this->createOffer($tenant, $entry, 'accepted');
            $entriesCreated++;
            $offersCreated++;
        }

        // ---- 1 expired (waited too long, date range passed) ----
        $customer = $customers[array_rand($customers)];
        $service  = $services[array_rand($services)];
        $this->createEntry($tenant, $customer, $service, 'expired');
        $entriesCreated++;

        // ---- 1 cancelled by customer ----
        $customer = $customers[array_rand($customers)];
        $service  = $services[array_rand($services)];
        $this->createEntry($tenant, $customer, $service, 'cancelled_by_customer');
        $entriesCreated++;

        $this->log("  Waitlist: {$entriesCreated} entries, {$offersCreated} offers, settings enabled.");
    }

    private function createEntry(Tenant $tenant, TenantCustomer $customer, TenantServiceItem $service, string $status): TenantWaitlistEntry
    {
        $now = Carbon::now();

        // Date range depends on status
        if ($status === 'active') {
            // Forward-looking: opens in next 2-30 days
            $start = $now->copy()->addDays(random_int(2, 14));
            $end   = $start->copy()->addDays(random_int(7, 30));
            $createdAt = $now->copy()->subDays(random_int(0, 14));
        } elseif ($status === 'fulfilled') {
            // Was waiting, got fulfilled 1-14 days ago
            $start = $now->copy()->subDays(random_int(7, 30));
            $end   = $now->copy()->subDays(random_int(1, 7));
            $createdAt = $start->copy()->subDays(random_int(3, 14));
        } elseif ($status === 'expired') {
            // Date range passed without fulfillment
            $start = $now->copy()->subDays(random_int(30, 60));
            $end   = $now->copy()->subDays(random_int(5, 20));
            $createdAt = $start->copy()->subDays(random_int(7, 21));
        } else {
            // cancelled
            $start = $now->copy()->addDays(random_int(5, 20));
            $end   = $start->copy()->addDays(14);
            $createdAt = $now->copy()->subDays(random_int(3, 30));
        }

        // Preferred days/times — about half of entries have preferences
        $preferredDays = null;
        $preferredTimeStart = null;
        $preferredTimeEnd = null;
        if (random_int(1, 100) <= 55) {
            $allDays = [1, 2, 3, 4, 5];
            shuffle($allDays);
            $preferredDays = array_slice($allDays, 0, random_int(2, 5));
            sort($preferredDays);
            $preferredTimeStart = ['09:00:00', '10:00:00', '12:00:00'][array_rand(['09:00:00', '10:00:00', '12:00:00'])];
            $preferredTimeEnd = ['13:00:00', '15:00:00', '17:00:00'][array_rand(['13:00:00', '15:00:00', '17:00:00'])];
        }

        $notes = $this->pickNote();

        $entry = TenantWaitlistEntry::create([
            'tenant_id'            => $tenant->id,
            'customer_id'          => $customer->id,
            'service_item_id'      => $service->id,
            'addon_ids'            => null,
            'date_range_start'     => $start->toDateString(),
            'date_range_end'       => $end->toDateString(),
            'preferred_days'       => $preferredDays,
            'preferred_time_start' => $preferredTimeStart,
            'preferred_time_end'   => $preferredTimeEnd,
            'notes'                => $notes,
            'status'               => $status,
            'created_at'           => $createdAt,
            'updated_at'           => $createdAt,
        ]);

        return $entry;
    }

    private function createOffer(Tenant $tenant, TenantWaitlistEntry $entry, string $status): void
    {
        $now = Carbon::now();

        $notifiedAt = $now->copy()->subDays(random_int(0, 7))->subHours(random_int(0, 23));

        $viewedAt = null;
        $acceptedAt = null;
        if (in_array($status, ['viewed', 'accepted'], true)) {
            $viewedAt = $notifiedAt->copy()->addMinutes(random_int(5, 120));
        }
        if ($status === 'accepted') {
            $acceptedAt = $viewedAt->copy()->addMinutes(random_int(1, 60));
        }

        $slotDateTime = $now->copy()->addDays(random_int(2, 14))
            ->setTime(random_int(9, 15), [0, 30][array_rand([0, 30])]);

        $offerExpiresAt = $notifiedAt->copy()->addHours(24);

        TenantWaitlistOffer::create([
            'tenant_id'             => $tenant->id,
            'waitlist_entry_id'     => $entry->id,
            'offer_token'           => Str::random(48),
            'slot_datetime'         => $slotDateTime,
            'slot_source'           => 'cancellation',
            'notified_at'           => $notifiedAt,
            'viewed_at'             => $viewedAt,
            'accepted_at'           => $acceptedAt,
            'status'                => $status,
            'offer_expires_at'      => $offerExpiresAt,
            'sms_sent'              => true,
            'email_sent'            => true,
            'created_at'            => $notifiedAt,
            'updated_at'            => $acceptedAt ?? $viewedAt ?? $notifiedAt,
        ]);
    }

    private function pickNote(): ?string
    {
        if (random_int(1, 100) > 60) return null;
        $notes = [
            'Would prefer morning if possible.',
            'Need it done before my trip on the 15th.',
            'Flexible on day, just need it soon.',
            'Only available on Saturdays.',
            'Any opening works — let me know ASAP.',
            'Would love to get in before the weekend ride.',
            'Happy to drop off the bike day-of.',
        ];
        return $notes[array_rand($notes)];
    }
}
