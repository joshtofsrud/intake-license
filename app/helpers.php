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

        $base = $t->custom_domain
            ? 'https://' . $t->custom_domain
            : 'https://' . $t->subdomain . '.' . config('intake.domain');

        return $base . '/' . ltrim($path, '/');
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
