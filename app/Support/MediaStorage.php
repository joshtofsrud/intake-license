<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantCampaignImage;
use App\Models\Tenant\TenantMedia;

/**
 * MARKER-MEDIA-STORAGE-METER — the one count of a shop's image storage.
 *
 * Before this, three upload paths each counted differently: campaign images
 * counted only campaign images, inventory photos counted only library images,
 * and builder uploads were never checked. Every path now asks this class.
 *
 * What counts: every library image (archived ones too — their files are kept
 * so live pages keep working) plus every campaign image. The limit is the
 * plan's allowance from config('intake.image_quotas.tiers'). Intake's own
 * site and the demo shop have no limit.
 */
class MediaStorage
{
    public static function usedBytes(string $tenantId): int
    {
        return self::libraryBytes($tenantId) + self::campaignBytes($tenantId);
    }

    public static function libraryBytes(string $tenantId): int
    {
        return (int) TenantMedia::where('tenant_id', $tenantId)->sum('bytes');
    }

    public static function campaignBytes(string $tenantId): int
    {
        return (int) TenantCampaignImage::where('tenant_id', $tenantId)->sum('bytes');
    }

    /** The plan's allowance in bytes. 0 means no limit. */
    public static function limitBytes(Tenant $tenant): int
    {
        if (($tenant->is_platform ?? false) || ($tenant->is_demo ?? false)) {
            return 0;
        }
        $tier = $tenant->plan_tier ?: 'starter';
        return (int) config("intake.image_quotas.tiers.{$tier}", 0);
    }

    /** Null when the upload fits; otherwise the message to show the shop. */
    public static function refuse(Tenant $tenant, int $incomingBytes): ?string
    {
        $limit = self::limitBytes($tenant);
        if ($limit <= 0) {
            return null;
        }
        $used = self::usedBytes((string) $tenant->id);
        if ($used + max(0, $incomingBytes) <= $limit) {
            return null;
        }
        return 'Storage full: using ' . self::human($used) . ' of ' . self::human($limit)
            . '. Upgrade your plan for more room.';
    }

    /** Everything the Media page meter needs. */
    public static function summary(Tenant $tenant): array
    {
        $library  = self::libraryBytes((string) $tenant->id);
        $campaign = self::campaignBytes((string) $tenant->id);
        $used     = $library + $campaign;
        $limit    = self::limitBytes($tenant);
        $pct      = $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0;

        return [
            'used'       => $used,
            'limit'      => $limit,
            'pct'        => $pct,
            'state'      => $limit <= 0 ? 'none' : ($used >= $limit ? 'full' : ($pct >= 80 ? 'near' : 'ok')),
            'library'    => $library,
            'campaign'   => $campaign,
            'used_h'     => self::human($used),
            'limit_h'    => $limit > 0 ? self::human($limit) : null,
            'library_h'  => self::human($library),
            'campaign_h' => self::human($campaign),
            'tier'       => $tenant->plan_tier ?: 'starter',
        ];
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) return rtrim(rtrim(number_format($bytes / 1024 ** 3, 1), '0'), '.') . ' GB';
        if ($bytes >= 1024 ** 2) return rtrim(rtrim(number_format($bytes / 1024 ** 2, 1), '0'), '.') . ' MB';
        if ($bytes >= 1024)      return round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }
}
