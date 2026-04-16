<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantCapacityRule;

class BookingModeService
{
    // ----------------------------------------------------------------
    // Preview what switching modes will change
    // Returns items that need review before switching
    // ----------------------------------------------------------------
    public static function previewSwitch(Tenant $tenant, string $toMode): array
    {
        $items = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        $preview = [];

        foreach ($items as $item) {
            if ($toMode === 'time_slots') {
                // Switching to time slots — show estimated duration from slot weight
                $estimatedDuration = self::durationFromWeight($item->slot_weight ?? 1);
                $preview[] = [
                    'id'                 => $item->id,
                    'name'               => $item->name,
                    'current_weight'     => $item->slot_weight ?? 1,
                    'estimated_duration' => $estimatedDuration,
                    'current_duration'   => $item->duration_minutes,
                    'needs_review'       => $item->duration_minutes === 60 && ($item->slot_weight ?? 1) > 1,
                ];
            } else {
                // Switching to drop-off — show estimated weight from duration
                $estimatedWeight = self::weightFromDuration($item->duration_minutes ?? 60);
                $preview[] = [
                    'id'               => $item->id,
                    'name'             => $item->name,
                    'current_duration' => $item->duration_minutes ?? 60,
                    'estimated_weight' => $estimatedWeight,
                    'current_weight'   => $item->slot_weight ?? 1,
                    'needs_review'     => $estimatedWeight !== ($item->slot_weight ?? 1),
                ];
            }
        }

        return $preview;
    }

    // ----------------------------------------------------------------
    // Execute the mode switch
    // Converts all service items to the new mode's data model
    // ----------------------------------------------------------------
    public static function executeSwitch(Tenant $tenant, string $toMode, array $overrides = []): void
    {
        $items = TenantServiceItem::where('tenant_id', $tenant->id)->get();

        foreach ($items as $item) {
            $updates = [];

            if ($toMode === 'time_slots') {
                // Use override if provided, otherwise estimate from slot weight
                $duration = $overrides[$item->id]['duration_minutes']
                    ?? self::durationFromWeight($item->slot_weight ?? 1);
                $updates['duration_minutes'] = (int) $duration;
            } else {
                // Use override if provided, otherwise estimate from duration
                $weight = $overrides[$item->id]['slot_weight']
                    ?? self::weightFromDuration($item->duration_minutes ?? 60);
                $updates['slot_weight'] = (int) min(4, max(1, $weight));
            }

            $item->update($updates);
        }

        // Update capacity rules with sensible defaults for time_slots mode
        if ($toMode === 'time_slots') {
            TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->whereNull('open_time')
                ->update([
                    'open_time'             => '09:00:00',
                    'close_time'            => '17:00:00',
                    'slot_interval_minutes' => 60,
                ]);
        }

        $tenant->update(['booking_mode' => $toMode]);
    }

    // ----------------------------------------------------------------
    // Conversion helpers
    // ----------------------------------------------------------------
    public static function durationFromWeight(int $weight): int
    {
        return match($weight) {
            1 => 60,
            2 => 120,
            3 => 180,
            4 => 240,
            default => 60,
        };
    }

    public static function weightFromDuration(int $minutes): int
    {
        return match(true) {
            $minutes > 180 => 4,
            $minutes > 120 => 3,
            $minutes > 60  => 2,
            default        => 1,
        };
    }
}
