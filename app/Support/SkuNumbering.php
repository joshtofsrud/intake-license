<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;

/**
 * MARKER-SKU-GEN: the shop's own SKUs, PREFIX + a five-digit number.
 *
 * The prefix lives in the tenant settings JSON (Settings > Business >
 * Numbering); without one it is the first word of the shop name, so a new
 * shop gets sensible SKUs without visiting settings. The next number is one
 * past the highest PREFIX+digits SKU already used, archived items included:
 * they keep their SKU (the unique index covers them), and reusing one would
 * fail on save.
 */
class SkuNumbering
{
    public const DIGITS = 5;

    public static function prefix(Tenant $tenant): string
    {
        $saved = self::clean(((array) ($tenant->settings ?? []))['sku_prefix'] ?? '');
        return $saved !== '' ? $saved : self::defaultPrefix($tenant);
    }

    public static function defaultPrefix(Tenant $tenant): string
    {
        $first = strtok(trim((string) $tenant->name) . ' ', ' ');
        $word  = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $first)), 0, 4);
        return ($word !== '' ? $word : 'ITEM') . '-';
    }

    /** Letters, digits and dashes, upper case, at most 12 characters. */
    public static function clean($value): string
    {
        return substr(preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim((string) $value))), 0, 12);
    }

    public static function next(Tenant $tenant): string
    {
        $prefix = self::prefix($tenant);
        $like   = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
        $max    = 0;

        TenantInventoryItem::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('sku', 'like', $like)
            ->pluck('sku')
            ->each(function ($sku) use ($prefix, &$max) {
                $rest = substr((string) $sku, strlen($prefix));
                if ($rest !== '' && ctype_digit($rest)) {
                    $max = max($max, (int) $rest);
                }
            });

        for ($n = $max + 1, $tries = 0; $tries < 500; $n++, $tries++) {
            $candidate = $prefix . str_pad((string) $n, self::DIGITS, '0', STR_PAD_LEFT);
            $taken = TenantInventoryItem::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('sku', $candidate)
                ->exists();
            if (! $taken) {
                return $candidate;
            }
        }

        return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}
