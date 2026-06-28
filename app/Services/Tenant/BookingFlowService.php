<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantServiceItem;
use Illuminate\Support\Collection;

/**
 * BookingFlowService — the single source of truth for booking *depth*.
 *
 * Distinct from booking_mode (the scheduling model: drop_off vs time_slots).
 * This decides whether the customer sees the full advanced flow, the curated
 * Simple menu, or a fork that lets them choose.
 *
 *   advanced  — current 6-step flow (default; nothing changes for anyone)
 *   simple    — 3-step curated flow (pick a service → schedule → details)
 *   choice    — open on a fork, then route to one of the above
 *
 * Both Simple and Advanced submit the same payload to BookingController@submit
 * (a single service_item in items[]), so there is no second booking engine —
 * just a lighter front door onto the same backend.
 */
class BookingFlowService
{
    public const ADVANCED = 'advanced';
    public const SIMPLE   = 'simple';
    public const CHOICE   = 'choice';

    public const MODES = [self::ADVANCED, self::SIMPLE, self::CHOICE];

    public function mode(Tenant $tenant): string
    {
        $mode = $tenant->booking_flow_mode ?? self::ADVANCED;
        return in_array($mode, self::MODES, true) ? $mode : self::ADVANCED;
    }

    /**
     * The curated Simple-mode menu: active service items flagged simple_enabled,
     * in the tenant's chosen order. Returns lightweight display rows.
     */
    public function simpleServices(Tenant $tenant): Collection
    {
        return TenantServiceItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('simple_enabled', true)
            ->orderBy('simple_sort')
            ->orderBy('sort_order')
            ->with('category:id,name')
            ->get()
            ->map(fn (TenantServiceItem $i) => [
                'id'          => $i->id,
                'name'        => $i->name,
                'tagline'     => $i->simple_tagline ?: ($i->description ? \Illuminate\Support\Str::limit(strip_tags($i->description), 90) : null),
                'price_cents' => (int) $i->price_cents,
                'duration'    => (int) $i->duration_minutes,
                'image_url'   => $i->image_url,
                'category'    => $i->category?->name,
            ])
            ->values();
    }

    /**
     * Whether to render the Simple front-end for this request.
     * In choice mode the customer's pick (?flow=quick|full) decides.
     */
    public function useSimpleView(string $mode, ?string $flow): bool
    {
        if ($mode === self::SIMPLE) {
            return true;
        }
        return $mode === self::CHOICE && $flow === 'quick';
    }

    /** Whether to show the fork screen (choice mode with no pick yet). */
    public function showFork(string $mode, ?string $flow): bool
    {
        return $mode === self::CHOICE && ! in_array($flow, ['quick', 'full'], true);
    }
}
