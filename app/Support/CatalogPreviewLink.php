<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-CATALOG-PREVIEW: a short-lived link from master admin to a shop's
 * own site that shows one catalog row as that shop would get it (its item
 * page, or its online store page). Signed with the app key, so only master
 * admin can make one; nothing is saved when it is opened.
 */
class CatalogPreviewLink
{
    public const KINDS = ['inventory', 'shop'];

    public static function url(Tenant $tenant, string $kind, string $catalogId, int $minutes = 30): string
    {
        $expires = now()->addMinutes($minutes)->timestamp;
        $path = $kind === 'inventory'
            ? '/admin/inventory/catalog-preview/' . $catalogId
            : '/shop/catalog-preview/' . $catalogId;
        return $tenant->urlTo($path, ['expires' => $expires, 'sig' => self::sign((string) $tenant->id, $kind, $catalogId, $expires)]);
    }

    public static function valid(string $tenantId, string $kind, string $catalogId, mixed $expires, mixed $sig): bool
    {
        if (! is_numeric($expires) || (int) $expires < now()->timestamp || ! is_string($sig)) {
            return false;
        }
        return hash_equals(self::sign($tenantId, $kind, $catalogId, (int) $expires), $sig);
    }

    private static function sign(string $tenantId, string $kind, string $catalogId, int $expires): string
    {
        return hash_hmac('sha256', implode('|', ['catalog-preview', $tenantId, $kind, $catalogId, $expires]), (string) config('app.key'));
    }
}
