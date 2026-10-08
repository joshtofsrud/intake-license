<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItemImage;
use App\Models\Tenant\TenantMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * where a library image is used, so Delete can refuse
 * while anything still shows it.
 *
 * Searches the shop's own records for the file's stored name (unique per
 * upload), which catches full URLs, /storage paths and JSON-escaped copies
 * alike. Inventory photos are linked by id. A place that can't be checked
 * counts as "in use": a wrong refusal is fixable, a broken page is not.
 *
 * Not checked: Rewind snapshots. Restoring an old version that used a
 * deleted image restores it without that image.
 */
class MediaUsage
{
    /** Tables holding image references: [table, columns, label, name column]. */
    private const PROBES = [
        ['tenant_page_sections',      ['content'],                        'Page',            null],
        ['tenant_pages',              ['og_image_url'],                   'Share image',     'title'],
        ['tenant_service_items',      ['image_url'],                      'Service',         'name'],
        ['tenant_service_categories', ['image_url'],                      'Service category','name'],
        ['tenant_rental_models',      ['image_url', 'photo_url', 'photos'], 'Rental model',  'name'],
        ['tenant_rental_units',       ['photo_url', 'photos'],            'Rental unit',     'name'],
        ['tenant_campaigns',          ['body_html', 'blocks'],            'Email campaign',  'name'],
    ];

    /** Plain-language places this image is used. Empty means safe to delete. */
    public static function find(TenantMedia $media): array
    {
        $tenantId = (string) $media->tenant_id;
        $needle   = basename((string) ($media->path ?: $media->filename));
        $out      = [];

        if ($needle === '' || $needle === '.') {
            return ['Its file name is missing, so its use cannot be checked'];
        }
        $like = '%' . $needle . '%';

        // The shop's own logo, light logo, favicon and site settings.
        try {
            $t = Tenant::find($tenantId);
            if ($t) {
                foreach (['logo_url' => 'Shop logo', 'logo_light_url' => 'Shop logo (light)', 'favicon_url' => 'Favicon'] as $col => $label) {
                    if (str_contains((string) ($t->{$col} ?? ''), $needle)) $out[] = $label;
                }
                if (str_contains(json_encode($t->settings ?? []), $needle)
                    || str_contains((string) json_encode($t->settings ?? [], JSON_UNESCAPED_SLASHES), $needle)) {
                    $out[] = 'Shop settings';
                }
            }
        } catch (\Throwable $e) {
            $out[] = 'Shop settings (could not be checked)';
        }

        // Inventory photos are linked by id, not by address.
        try {
            $n = TenantInventoryItemImage::where('tenant_id', $tenantId)->where('media_id', $media->id)->count();
            if ($n > 0) $out[] = $n === 1 ? 'Inventory: 1 item' : "Inventory: {$n} items";
        } catch (\Throwable $e) {
            $out[] = 'Inventory (could not be checked)';
        }

        foreach (self::PROBES as [$table, $cols, $label, $nameCol]) {
            try {
                if (! Schema::hasTable($table)) continue;
                $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
                if (! $cols) continue;

                $q = DB::table($table)->where('tenant_id', $tenantId)
                    ->where(function ($w) use ($cols, $like) {
                        foreach ($cols as $c) $w->orWhere($c, 'like', $like);
                    });

                if ($table === 'tenant_page_sections') {
                    $titles = DB::table('tenant_pages')
                        ->whereIn('id', (clone $q)->select('page_id'))
                        ->pluck('title')->all();
                    foreach ($titles as $title) $out[] = 'Page: ' . ($title ?: 'Untitled');
                    continue;
                }

                $names = ($nameCol && Schema::hasColumn($table, $nameCol))
                    ? $q->limit(5)->pluck($nameCol)->all()
                    : array_fill(0, min(5, $q->count()), null);
                foreach ($names as $name) $out[] = $label . ($name ? ': ' . $name : '');
            } catch (\Throwable $e) {
                $out[] = $label . ' (could not be checked)';
            }
        }

        return array_values(array_unique($out));
    }
}
