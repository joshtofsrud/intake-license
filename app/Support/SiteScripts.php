<?php

namespace App\Support;

/**
 * MARKER-SITE-SCRIPTS: code from outside tools (chat widgets, pixels) that a shop
 * runs on its public site. Edited in the page builder's Site scripts panel, kept in
 * tenants.settings.site_scripts. Never runs inside Intake; runs in the builder
 * preview only when the shop turns that on; skips pages where people pay or sign
 * in unless a script is set to every page.
 */
class SiteScripts
{
    public const MAX = 12;
    public const MAX_CODE = 20000;
    public const WHERE = ['body', 'head'];
    public const PAGES = ['safe', 'all'];

    /** Public pages where people pay or sign in (route-name prefixes). */
    private const SENSITIVE = [
        'tenant.checkout.', 'tenant.customer.', 'tenant.booking', 'tenant.paypal.', 'tenant.gift-cards.',
        'tenant.rentals.reserve', 'tenant.rentals.extension', 'tenant.pay_display.',
    ];

    public static function all($tenant): array
    {
        if (! $tenant) return [];
        $raw = $tenant->settings['site_scripts'] ?? [];
        return self::clean(is_array($raw) ? $raw : [])['rows'];
    }

    public static function previewOn($tenant): bool
    {
        return (bool) ($tenant->settings['site_scripts_preview'] ?? false);
    }

    /** ['rows' => cleaned rows, 'error' => first problem or null] */
    public static function clean(array $rows): array
    {
        $out = []; $err = null;
        foreach (array_slice(array_values($rows), 0, self::MAX) as $i => $r) {
            if (! is_array($r)) continue;
            $code = trim((string) ($r['code'] ?? ''));
            $name = mb_substr(trim((string) ($r['name'] ?? '')), 0, 40);
            if ($code === '') { $err = $err ?? 'Script ' . ($i + 1) . ' has no code.'; continue; }
            if (! str_starts_with($code, '<')) { $err = $err ?? '“' . ($name ?: 'Script ' . ($i + 1)) . '” should start with a tag such as <script.'; continue; }
            if (mb_strlen($code) > self::MAX_CODE) { $err = $err ?? '“' . ($name ?: 'Script ' . ($i + 1)) . '” is too long.'; continue; }
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($r['id'] ?? ''))) ?: substr(md5($code . $i . microtime()), 0, 10);
            $skip = array_values(array_slice(array_filter(array_map('strval', (array) ($r['skip'] ?? [])), fn ($v) => preg_match('/^[a-z0-9-]{1,40}$/i', $v)), 0, 200));
            $out[] = [
                'id'    => mb_substr($id, 0, 16),
                'name'  => $name !== '' ? $name : 'Script ' . ($i + 1),
                'code'  => $code,
                'where' => in_array($r['where'] ?? '', self::WHERE, true) ? $r['where'] : 'body',
                'pages' => in_array($r['pages'] ?? '', self::PAGES, true) ? $r['pages'] : 'safe',
                'on'    => filter_var($r['on'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'skip'  => $skip,
            ];
        }
        return ['rows' => $out, 'error' => $err];
    }

    /** The code for one spot on the current page ('' when nothing applies). */
    public static function render(string $part, $tenant, ?string $pageId = null, bool $preview = false): string
    {
        try {
            if (! $tenant || ($tenant->is_platform ?? false)) return '';
            if ($preview && ! self::previewOn($tenant)) return '';
            $route = (string) (optional(request()->route())->getName() ?? '');
            $sensitive = false;
            foreach (self::SENSITIVE as $p) if ($route !== '' && str_starts_with($route, $p)) { $sensitive = true; break; }
            $out = '';
            foreach (self::all($tenant) as $s) {
                if (! $s['on'] || $s['where'] !== $part) continue;
                if ($sensitive && $s['pages'] !== 'all') continue;
                if ($pageId && in_array($pageId, $s['skip'], true)) continue;
                $out .= "\n<!-- site script: " . str_replace('--', '', e($s['name'])) . " -->\n" . $s['code'] . "\n";
            }
            return $out;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
