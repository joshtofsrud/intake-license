<?php
// MARKER-PATCH-152B

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantDelivery;
use App\Models\Tenant\TenantDeliveryResource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * TenantDeliveryService — fetches deliveries for a day or week,
 * already grouped/sorted for the view layer.
 *
 * All queries tenant-scoped first via the (tenant_id, scheduled_at)
 * composite index from patch 152-a. Cancelled deliveries are excluded
 * by default — tenants want to plan, not look at noise.
 */
class TenantDeliveryService
{
    protected Tenant $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * One day of deliveries, ordered by scheduled_at ASC.
     * @return \Illuminate\Support\Collection<TenantDelivery>
     */
    public function forDay(Carbon $date)
    {
        $tz    = $this->tenant->timezone ?? config('app.timezone', 'UTC');
        // MARKER-PATCH-203 — build the day window in tenant-local wall time, then
        // convert the BOUNDS to UTC for the query. scheduled_at is stored UTC (the
        // write path converts on save, patch-158); the read window must match or
        // the day boundary is offset by the tz offset — late-yesterday deliveries
        // leaked into "today" and early-today ones fell out.
        $start = $date->copy()->setTimezone($tz)->startOfDay()->utc();
        $end   = $date->copy()->setTimezone($tz)->endOfDay()->utc();

        return TenantDelivery::query()
            ->with(['customer', 'deliveryResource'])
            ->where('tenant_id', $this->tenant->id)
            ->where('status', '!=', TenantDelivery::STATUS_CANCELLED)
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Seven days starting from Monday of the given date's week.
     * Returns array keyed by Y-m-d, each value a collection of deliveries.
     */
    public function forWeek(Carbon $date): array
    {
        $tz    = $this->tenant->timezone ?? config('app.timezone', 'UTC');
        // MARKER-PATCH-203 — local week window, bounds converted to UTC for the
        // query (scheduled_at is stored UTC). Result bucketing below re-localizes.
        $localStart = $date->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY);
        $start = $localStart->copy()->utc();
        $end   = $localStart->copy()->addDays(7)->subSecond()->utc();

        $rows = TenantDelivery::query()
            ->with(['customer', 'deliveryResource'])
            ->where('tenant_id', $this->tenant->id)
            ->where('status', '!=', TenantDelivery::STATUS_CANCELLED)
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get();

        $byDay = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $localStart->copy()->addDays($i)->toDateString(); // MARKER-PATCH-203
            $byDay[$d] = collect();
        }
        foreach ($rows as $row) {
            $key = $row->scheduled_at->copy()->setTimezone($tz)->toDateString();
            if (isset($byDay[$key])) {
                $byDay[$key]->push($row);
            }
        }
        return $byDay;
    }

    /**
     * Active delivery resources for the tenant. Sorted for column order.
     */
    public function activeResources()
    {
        return TenantDeliveryResource::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Customers list for the create drawer.
     * Just returns id+name+email+phone+address from tenant_customers
     * limited to active. Cached at the request level.
     */
    public function customersForPicker()
    {
        // Concatenate the multi-part address client-side after fetch.
        $rows = \DB::table('tenant_customers')
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(2000) // huge tenants will need an autocomplete later
            ->get([
                'id', 'first_name', 'last_name', 'email', 'phone',
                'address_line1', 'address_line2', 'city', 'state', 'postcode',
            ]);

        // Compose a one-line "address" field for the dropdown's data-address attr.
        return $rows->map(function ($r) {
            $parts = array_filter([
                $r->address_line1,
                $r->address_line2,
                trim(($r->city ? $r->city : '') . ($r->state ? ', ' . $r->state : '')),
                $r->postcode,
            ]);
            $r->address = implode(', ', $parts);
            return $r;
        });
    }

    /**
     * Check whether a proposed delivery would overlap an existing
     * delivery on the SAME delivery_resource_id.
     *
     * Returns the conflicting delivery if any, otherwise null.
     * Pass $excludeId to skip a specific delivery (for edits).
     */
    public function findResourceConflict(
        ?string $deliveryResourceId,
        CarbonImmutable $start,
        int $windowMinutes,
        ?string $excludeId = null
    ): ?TenantDelivery {
        if (empty($deliveryResourceId)) return null;
        $end = $start->copy()->addMinutes($windowMinutes);

        $q = TenantDelivery::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('delivery_resource_id', $deliveryResourceId)
            ->where('status', '!=', TenantDelivery::STATUS_CANCELLED)
            // Overlap: existing.start < new.end AND existing.end > new.start.
            // existing.end is scheduled_at + window_minutes.
            ->whereRaw('scheduled_at < ?', [$end])
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL window_minutes MINUTE) > ?', [$start]);

        if ($excludeId) $q->where('id', '!=', $excludeId);

        return $q->orderBy('scheduled_at')->first();
    }
}