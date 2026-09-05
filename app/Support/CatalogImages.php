<?php

namespace App\Support;

use App\Models\Tenant\TenantDistributorCatalogSubscription;

/**
 * MARKER-MODAL-QBP-IMAGES — turn a catalog's stored images into URLs a browser
 * can load.
 *
 * HLC and BTI store full URLs and pass straight through. QBP stores bare
 * filenames that mean nothing without this tenant's CLS prefix, which embeds
 * their own Image Service ID — it is read per tenant and never shared, and the
 * licence requires hotlinking those URLs rather than copying the files.
 *
 * This existed only inside the inventory view, so every other surface showing a
 * catalog photo either duplicated it or, as the item modal did, shipped a
 * filename to an <img src> and drew a broken icon.
 */
class CatalogImages
{
    /**
     * @param  iterable  $images  the catalog's stored image entries
     * @return array<string>      URLs safe to put in a src attribute
     */
    public static function urls($images, ?string $distributorCode, ?string $tenantId = null, int $limit = 8): array
    {
        $prefix = $distributorCode === 'QBP'
            ? self::clsPrefix($tenantId)
            : null;

        $size = config('distributors.qbp_cls.image_size', 'p350x350m');

        return collect((array) $images)
            ->map(function ($img) use ($prefix, $size) {
                $raw = is_array($img)
                    ? ($img['url'] ?? $img['Url'] ?? $img['path'] ?? $img['fileName'] ?? $img['src'] ?? null)
                    : (is_string($img) ? $img : null);

                if (! is_string($raw) || trim($raw) === '') {
                    return null;
                }
                $raw = trim($raw);

                // Already a URL (HLC, BTI) — leave it alone.
                if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://') || str_starts_with($raw, '//')) {
                    return $raw;
                }

                // A bare filename (QBP) is only a URL once the tenant's CLS
                // prefix is applied. Without a subscription there is no licence
                // to display it, so return nothing rather than a guess: a
                // missing photo is honest, a broken one looks like bad data.
                return $prefix
                    ? \App\Services\Distributors\QbpClsClient::imageUrl($prefix, $size, $raw)
                    : null;
            })
            ->filter()
            ->values()
            ->take($limit)
            ->all();
    }

    private static function clsPrefix(?string $tenantId): ?string
    {
        $tenantId = $tenantId ?: (tenant()?->id);
        if (! $tenantId) {
            return null;
        }

        return TenantDistributorCatalogSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('distributor_code', 'QBP')
            ->value('cls_image_url');
    }
}
