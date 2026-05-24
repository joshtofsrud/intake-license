<?php

if (! function_exists('tenant')) {
    /**
     * Get the current tenant instance, or null if not in a tenant context.
     *
     * @return \App\Models\Tenant|null
     */
    function tenant(): ?\App\Models\Tenant
    {
        return app('tenant');
    }
}

if (! function_exists('tenant_url')) {
    /**
     * Generate a URL for the current tenant's public site.
     *
     * @param  string $path
     * @return string
     */
    function tenant_url(string $path = ''): string
    {
        $t = tenant();
        if (! $t) return url($path);

        // MARKER-PATCH-123 — delegate to Tenant::publicUrl() so custom
        // domains served via tenant_domains (and legacy custom_domain) are
        // both handled in one place.
        return $t->publicUrl() . '/' . ltrim($path, '/');
    }
}

if (! function_exists('format_money')) {
    /**
     * Format cents as a currency string using the current tenant's symbol.
     *
     * @param  int    $cents
     * @param  string $symbol  Fallback if no tenant in scope
     * @return string
     */
    function format_money(int $cents, string $symbol = '$'): string
    {
        $sym = tenant()?->currency_symbol ?? $symbol;
        return $sym . number_format($cents / 100, 2);
    }
}

if (! function_exists('debug_log')) {
    /**
     * Shortcut to the DebugLogService singleton.
     *
     *   debug_log()->error($exception);
     *   debug_log()->audit('settings_updated', 'Tenant updated', $tenant, $diff);
     *   debug_log()->mail($recipient, 'booking.confirmation');
     */
    function debug_log(): \App\Services\DebugLogService
    {
        return app(\App\Services\DebugLogService::class);
    }
}
