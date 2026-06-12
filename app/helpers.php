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

if (! function_exists('tlocal')) {
    /**
     * MARKER-PATCH-189 — Render a UTC datetime instant in the current tenant's
     * timezone. THE canonical way to display any 'datetime'-cast column
     * (scheduled_at, starts_at, created_at, sent_at, …). Storing UTC and
     * converting at the edge is the standard; this makes the conversion
     * impossible to forget. For naive wall-clock values (appointment_time),
     * do NOT use this — those are already tenant-local and must not be shifted.
     *
     * @param  \Carbon\Carbon|\DateTimeInterface|string|null $instant  UTC instant
     * @param  string $format  PHP date format (default: '8:30 AM')
     * @return string          Empty string for null
     */
    function tlocal($instant, string $format = 'g:i A'): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        // A bare string/DateTime is assumed UTC (matches how the DB stores
        // 'datetime' casts). Carbon instances already carry their own tz.
        return $c->setTimezone($tz)->format($format);
    }
}

if (! function_exists('tnow')) {
    /**
     * MARKER-PATCH-234C — "now" as a tenant-local Carbon. Use for
     * date-of-day boundaries the tenant will see (today's pickups, week
     * windows). For storage timestamps and created_at comparisons use plain
     * now() — those are UTC. Mirrors DashboardDataService::tnow().
     *
     * @return \Carbon\Carbon
     */
    function tnow(): \Carbon\Carbon
    {
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        return \Carbon\Carbon::now($tz);
    }
}

if (! function_exists('tlocal_date')) {
    /** Tenant-local date, e.g. "May 31, 2026". @see tlocal() */
    function tlocal_date($instant, string $format = 'M j, Y'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_datetime')) {
    /** Tenant-local date + time, e.g. "May 31, 2026 8:30 AM". @see tlocal() */
    function tlocal_datetime($instant, string $format = 'M j, Y g:i A'): string
    {
        return tlocal($instant, $format);
    }
}

if (! function_exists('tlocal_carbon')) {
    /**
     * Same conversion as tlocal() but returns the Carbon (for further work /
     * comparisons), not a formatted string. Returns null for null input.
     *
     * @return \Carbon\Carbon|null
     */
    function tlocal_carbon($instant): ?\Carbon\Carbon
    {
        if ($instant === null || $instant === '') {
            return null;
        }
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $c  = $instant instanceof \Carbon\Carbon
            ? $instant->copy()
            : \Carbon\Carbon::parse($instant, 'UTC');
        return $c->setTimezone($tz);
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
