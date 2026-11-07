<?php

namespace App\Support;

use App\Models\Tenant\TenantAppointment;
use Illuminate\Support\Carbon;

/**
 * MARKER-MULTI-ASSET-COUNT: the items on a multi-asset appointment, each with
 * its services and when they run. Shown as "3 items" with a hover / tap list
 * on the calendar, the work order list and the Studio dashboard.
 *
 * Assets have no start times of their own; the times lay each item's services
 * end to end from the appointment's start, in the order the items were added.
 * Needs the appointment's 'assets' and 'items' loaded. Fewer than two: none.
 */
class AssetSummary
{
    public static function for(TenantAppointment $a, ?string $tz = null): array
    {
        if (! $a->relationLoaded('assets') || $a->assets->count() < 2) return [];
        $tz ??= function_exists('tenant') && tenant() ? tenant()->timezone() : config('app.timezone');
        $items = $a->relationLoaded('items') ? $a->items : collect();
        $cursor = $a->appointment_time && $a->appointment_date
            ? Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time, $tz)
            : null;
        $out = [];
        foreach ($a->assets->sortBy('sort_order') as $as) {
            $mine = $items->where('appointment_asset_id', $as->id);
            $mins = (int) $mine->sum(fn ($i) => $i->duration_minutes_override ?? $i->duration_minutes_snapshot ?? 0);
            $when = null;
            if ($cursor && $mins > 0) {
                $end = $cursor->copy()->addMinutes($mins);
                $when = $cursor->format('g:i') . '–' . $end->format('g:i A');
                $cursor = $end;
            } elseif ($mins > 0) {
                $when = $mins >= 60 ? intdiv($mins, 60) . 'h' . ($mins % 60 ? ' ' . ($mins % 60) . 'm' : '') : $mins . ' min';
            }
            $out[] = [
                'name'     => trim($as->asset_name_snapshot . ($as->identifier_snapshot ? ' · ' . $as->identifier_snapshot : '')),
                'services' => $mine->pluck('item_name_snapshot')->filter()->implode(', ') ?: 'No services yet',
                'when'     => $when,
            ];
        }

        return $out;
    }

    /** The eager loads the summary needs, for list and calendar queries. */
    public const WITH = [
        'assets:id,appointment_id,asset_name_snapshot,identifier_snapshot,sort_order',
    ];
}
