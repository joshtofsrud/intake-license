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

if (! function_exists('is_impersonating')) {
    /**
     * MARKER-IMPERSONATION-PIN — is this session a platform operator acting
     * as a tenant user? Every PIN gate must consult this: the operator
     * cannot know the tenant's PIN, so enforcing one locks them out of a
     * session they legitimately hold. Reaching impersonation already
     * required a master admin login, which is the stronger check.
     */
    function is_impersonating(): bool
    {
        return session()->has('impersonating_from');
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

if (! function_exists('tender_label')) {
    /**
     * MARKER-PATCH-630 — human label for a payment_method key.
     * 'cash_app' → 'Cash App', 'custom_house_account' → 'House account'.
     * Prefers the tenant's configured method name when available.
     */
    function tender_label(?string $key): string
    {
        if (!$key) return '';
        static $cache = [];
        $tid = function_exists('tenant') && app()->bound('tenant') && tenant() ? tenant()->id : null;
        $ck = ($tid ?? '-') . ':' . $key;
        if (isset($cache[$ck])) return $cache[$ck];

        $name = null;
        if ($tid) {
            try {
                $name = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tid)
                    ->where('method_key', $key)->value('name');
            } catch (\Throwable $e) { /* table may not exist yet */ }
        }
        if (!$name) {
            $name = ucfirst(str_replace('_', ' ', preg_replace('/^custom_/', '', $key)));
        }
        return $cache[$ck] = $name;
    }
}

if (! function_exists('tenant_day_utc_range')) {
    /**
     * MARKER-TZ-WAVE1 — the ONE way to bound a tenant-local calendar day
     * when querying UTC timestamp columns. Returns [startUtc, endUtc)
     * for the given tenant-local day.
     *
     * WRONG: ->whereDate('paid_at', tnow()->toDateString())
     *        (compares the UTC date of the stored instant — evening rows
     *        land on tomorrow)
     * RIGHT: [$s, $e] = tenant_day_utc_range(tnow());
     *        ->where('paid_at', '>=', $s)->where('paid_at', '<', $e)
     *
     * @param  \Carbon\Carbon|string  $day  tenant-local day (Carbon or Y-m-d)
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    function tenant_day_utc_range(\Carbon\Carbon|string $day, ?string $tz = null): array
    {
        $tz ??= tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $local = $day instanceof \Carbon\Carbon
            ? $day->copy()->setTimezone($tz)->startOfDay()
            : \Carbon\Carbon::parse($day, $tz)->startOfDay();

        return [$local->copy()->utc(), $local->copy()->addDay()->utc()];
    }
}

if (! function_exists('tenant_tz_offset_expr')) {
    /**
     * MARKER-TZ-WAVE4 — DST-correct SQL expression converting a UTC
     * timestamp COLUMN to tenant-local time for bucketing (DATE()/HOUR()).
     *
     * WRONG: $off = Carbon::now($tz)->utcOffset() * 60;           // TODAY's offset
     *        DATE(DATE_ADD(recorded_at, INTERVAL $off SECOND))    // applied to history
     *        — rows across a DST change bucket an hour off, and around
     *          midnight land on the wrong local day.
     * RIGHT: [$expr, $b] = tenant_tz_offset_expr('recorded_at', $tz, $startUtc, $endUtc);
     *        ->selectRaw("DATE($expr) as d, ...", $b)
     *
     * Builds a CASE over the DST transitions inside [$startUtc, $endUtc] so
     * each row gets the offset that was in force at its own instant.
     * $column MUST be a trusted literal (never user input).
     *
     * @return array{0:string,1:array}  [sql fragment, bindings]
     */
    function tenant_tz_offset_expr(string $column, string $tz, \Carbon\Carbon $startUtc, \Carbon\Carbon $endUtc): array
    {
        $zone = new \DateTimeZone($tz);
        $transitions = $zone->getTransitions($startUtc->timestamp, $endUtc->timestamp) ?: [];

        // First entry describes the offset in force at range start; the rest
        // are actual changes inside the range.
        $eras = [];
        foreach ($transitions as $t) {
            $eras[] = ['ts' => (int) $t['ts'], 'offset' => (int) $t['offset']];
        }
        if ($eras === []) {
            $eras[] = ['ts' => $startUtc->timestamp, 'offset' => $zone->getOffset($startUtc->toDateTime())];
        }

        if (count($eras) === 1) {
            return ["DATE_ADD({$column}, INTERVAL ? SECOND)", [$eras[0]['offset']]];
        }

        $sql = 'CASE';
        $bindings = [];
        for ($i = 1; $i < count($eras); $i++) {
            $sql .= " WHEN {$column} < ? THEN ?";
            $bindings[] = \Carbon\Carbon::createFromTimestampUTC($eras[$i]['ts'])->toDateTimeString();
            $bindings[] = $eras[$i - 1]['offset'];
        }
        $sql .= ' ELSE ? END';
        $bindings[] = $eras[count($eras) - 1]['offset'];

        return ["DATE_ADD({$column}, INTERVAL ({$sql}) SECOND)", $bindings];
    }
}

// MARKER-WELCOME-LOGO-SMART — initials of the WORDS, not the first letters.
// "Oakridge Bike Shop" is OBS, not OA.
if (! function_exists('brand_initials')) {
    function brand_initials(?string $name, int $max = 3): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }

        $words = preg_split('/[\s\-\/&]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Drop noise words so "The Corner Shop" reads TCS, not THE.
        $skip = ['the', 'and', 'of', 'a', 'an', '&'];
        $kept = array_values(array_filter($words, fn ($w) => ! in_array(mb_strtolower($w), $skip, true)));
        if (count($kept) < 2) {
            $kept = $words;
        }

        if (count($kept) === 1) {
            return mb_strtoupper(mb_substr($kept[0], 0, 2));
        }

        $out = '';
        foreach (array_slice($kept, 0, $max) as $w) {
            $out .= mb_substr($w, 0, 1);
        }

        return mb_strtoupper($out);
    }
}
