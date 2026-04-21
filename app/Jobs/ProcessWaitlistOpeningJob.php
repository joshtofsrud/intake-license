<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Models\Tenant\TenantWaitlistSettings;
use App\Models\Tenant\TenantWaitlistSimilarMap;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWaitlistOpeningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries    = 3;
    public int $backoff  = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $slotDatetime,     // ISO datetime
        public readonly string $serviceItemId,
        public readonly string $slotSource,        // 'cancellation' | 'new_hours' | 'manual_tenant_offer'
        public readonly ?string $triggeringAppointmentId = null
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) return;
        if (!$tenant->hasWaitlistFeature()) return;

        $settings = TenantWaitlistSettings::forTenant($tenant);
        if (!$settings->enabled) return;

        // Check source is enabled for this tenant
        if ($this->slotSource === 'cancellation' && !$settings->include_cancellations) return;
        if ($this->slotSource === 'new_hours'    && !$settings->include_new_openings) return;
        if ($this->slotSource === 'manual_tenant_offer' && !$settings->include_manual_offers) return;

        $slot = Carbon::parse($this->slotDatetime);
        if ($slot->isPast()) {
            Log::info('Waitlist skipped (slot in past)', [
                'tenant_id' => $tenant->id,
                'slot'      => $this->slotDatetime,
            ]);
            return;
        }

        $service = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $this->serviceItemId)
            ->first();
        if (!$service) return;

        $candidates = $this->findCandidates($tenant, $service, $slot, $settings);
        if ($candidates->isEmpty()) {
            Log::info('Waitlist opening — no matching candidates', [
                'tenant_id'  => $tenant->id,
                'service_id' => $service->id,
                'slot'       => $this->slotDatetime,
            ]);
            return;
        }

        foreach ($candidates as $entry) {
            $offer = TenantWaitlistOffer::create([
                'tenant_id'                 => $tenant->id,
                'waitlist_entry_id'         => $entry->id,
                'offer_token'               => TenantWaitlistOffer::generateToken(),
                'slot_datetime'             => $slot,
                'slot_source'               => $this->slotSource,
                'triggering_appointment_id' => $this->triggeringAppointmentId,
                'status'                    => 'pending',
                'offer_expires_at'          => $slot,
            ]);

            SendWaitlistOfferNotificationJob::dispatch($offer->id)->afterCommit();
        }

        Log::info('Waitlist offers created', [
            'tenant_id'  => $tenant->id,
            'service_id' => $service->id,
            'slot'       => $this->slotDatetime,
            'count'      => $candidates->count(),
        ]);
    }

    private function findCandidates(
        Tenant $tenant,
        TenantServiceItem $service,
        Carbon $slot,
        TenantWaitlistSettings $settings
    ) {
        $matchingServiceIds = $this->matchingServiceIds($tenant, $service, $settings);

        $query = TenantWaitlistEntry::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereIn('service_item_id', $matchingServiceIds)
            ->whereDate('date_range_start', '<=', $slot->toDateString())
            ->whereDate('date_range_end', '>=', $slot->toDateString());

        $entries = $query->with('customer')->get();

        $filtered = $entries->filter(function (TenantWaitlistEntry $entry) use ($slot, $settings) {
            if (!$entry->matchesPreferredDays($slot)) return false;
            if (!$entry->matchesPreferredTime($slot)) return false;

            if ($settings->exclude_first_time_customers) {
                $priorCount = $entry->customer?->appointments()
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->count() ?? 0;
                if ($priorCount === 0) return false;
            }

            return true;
        });

        return $filtered->values();
    }

    private function matchingServiceIds(Tenant $tenant, TenantServiceItem $service, TenantWaitlistSettings $settings): array
    {
        $ids = [$service->id];

        switch ($settings->similar_match_rule) {
            case 'exact_only':
                break;

            case 'by_duration':
                $duration = (int) $service->duration_minutes;
                $similar = TenantServiceItem::where('tenant_id', $tenant->id)
                    ->where('duration_minutes', $duration)
                    ->where('id', '!=', $service->id)
                    ->pluck('id')
                    ->toArray();
                $ids = array_merge($ids, $similar);
                break;

            case 'by_category':
                $catId = $service->category_id;
                if ($catId) {
                    $similar = TenantServiceItem::where('tenant_id', $tenant->id)
                        ->where('category_id', $catId)
                        ->where('id', '!=', $service->id)
                        ->pluck('id')
                        ->toArray();
                    $ids = array_merge($ids, $similar);
                }
                break;

            case 'by_tenant_mapping':
                $mapped = TenantWaitlistSimilarMap::where('tenant_id', $tenant->id)
                    ->where('service_item_id', $service->id)
                    ->pluck('substitutable_service_item_id')
                    ->toArray();
                $ids = array_merge($ids, $mapped);
                break;
        }

        return array_values(array_unique($ids));
    }
}
