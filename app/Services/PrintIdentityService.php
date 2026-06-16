<?php

namespace App\Services;

/**
 * Shared visual identity for every printed document — work-order tags,
 * register receipts, and pickup/dropoff slips. One source for the logo,
 * paper width, feed, and header/footer, instead of a block copy-pasted into
 * each print method.
 *
 * MARKER-PATCH-332
 */
class PrintIdentityService
{
    public static function forTenant($tenant): array
    {
        $cfg = (array) ($tenant->settings['work_order_tag'] ?? []);

        return [
            'paper'       => in_array(($cfg['paper'] ?? '80mm'), ['80mm', '58mm'], true) ? ($cfg['paper'] ?? '80mm') : '80mm',
            'logo_path'   => $cfg['logo_path'] ?? null,
            'logo_size'   => in_array(($cfg['logo_size'] ?? 'medium'), ['small', 'medium', 'large', 'xl'], true) ? ($cfg['logo_size'] ?? 'medium') : 'medium',
            'header_text' => (string) ($cfg['header_text'] ?? ''),
            'footer_text' => (string) ($cfg['footer_text'] ?? ''),
            'feed_mm'     => (int) ($cfg['feed_mm'] ?? 0),
        ];
    }
}
