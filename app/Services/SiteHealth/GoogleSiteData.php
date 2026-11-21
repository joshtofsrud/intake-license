<?php

namespace App\Services\SiteHealth;

use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Models\Tenant\TenantGoogleConnection;
use App\Models\Tenant\TenantInventoryItem;
use App\Support\Seo;
use App\Support\ShopGa4;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MARKER-SITE-HEALTH: one Google sign-in a shop, read for four things:
 *   gsc  Search Console    the shop's real searches, clicks and position
 *   ga   Analytics         where visits come from
 *   mc   Merchant Center   which items Google shows, and which it turned down
 *   gbp  Business Profile  rating and reviews (and the shop's replies)
 *
 * Intake only reads, with one exception the shop presses a button for: posting a
 * reply to a review. Each source stands alone: one failing never hides the others.
 */
class GoogleSiteData
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SOURCES = ['gsc' => 'Rankings', 'ga' => 'Visitors', 'mc' => 'Shopping listings', 'gbp' => 'Reviews'];

    private const SCOPE = [
        'gsc' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'ga'  => 'https://www.googleapis.com/auth/analytics.readonly',
        'mc'  => 'https://www.googleapis.com/auth/content',
        'gbp' => 'https://www.googleapis.com/auth/business.manage',
    ];

    // ------------------------------------------------------------- switches

    /** What master admin has switched on. */
    public static function settings(): array
    {
        $s = (array) (PlatformSettings::current()->site_health_settings ?? []);

        return ['google' => (bool) ($s['google'] ?? false), 'reviews' => (bool) ($s['reviews'] ?? false)];
    }

    public static function configured(): bool
    {
        return (bool) (config('services.google.client_id') && config('services.google.client_secret'));
    }

    /** Can shops connect Google at all? */
    public static function available(): bool
    {
        return self::configured() && self::settings()['google'];
    }

    /** The sources on offer right now, in the order they are shown. */
    public static function sources(Tenant $tenant): array
    {
        $out = ['gsc', 'ga'];
        if (Seo::storefrontOn($tenant)) {
            $out[] = 'mc';
        }
        if (self::settings()['reviews']) {
            $out[] = 'gbp';
        }

        return $out;
    }

    public static function callbackUrl(): string
    {
        return 'https://app.' . Seo::rootDomain() . '/integrations/google/callback';
    }

    // ---------------------------------------------------------------- OAuth

    /** Start a sign-in: remember who asked, return where to send them. */
    public function begin(Tenant $tenant, ?string $userId, string $returnUrl): string
    {
        $nonce = Str::random(48);
        Cache::put('site_google_oauth:' . $nonce, ['tenant_id' => $tenant->id, 'user_id' => $userId, 'return' => $returnUrl], now()->addMinutes(15));
        $scopes = array_merge(['openid', 'email'], array_map(fn ($s) => self::SCOPE[$s], self::sources($tenant)));

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => self::callbackUrl(),
            'response_type' => 'code', 'scope' => implode(' ', $scopes),
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false', 'state' => $nonce,
        ]);
    }

    public function claim(string $nonce): ?array
    {
        return $nonce !== '' ? Cache::pull('site_google_oauth:' . $nonce) : null;
    }

    /** Finish a sign-in: tokens, who it is, what they can see. */
    public function complete(array $pending, string $code): TenantGoogleConnection
    {
        $r = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => self::callbackUrl(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->ok() || ! $r->json('access_token')) {
            throw new RuntimeException('Google did not finish the sign-in. Try Connect again.');
        }
        if (! $r->json('refresh_token')) {
            throw new RuntimeException('Google did not give lasting access. Remove Intake under your Google Account\'s third-party access, then connect again.');
        }

        $email = null;
        if ($id = $r->json('id_token')) {
            $parts = explode('.', $id);
            $claims = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
            $email = is_array($claims) ? ($claims['email'] ?? null) : null;
        }

        $conn = TenantGoogleConnection::firstOrNew(['tenant_id' => $pending['tenant_id']]);
        $conn->fill([
            'email' => $email, 'access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token'),
            'token_expires_at' => now()->addSeconds(max(60, (int) $r->json('expires_in', 3600) - 60)),
            'scopes' => Str::limit((string) $r->json('scope', ''), 590, ''),
            'connected_by' => $pending['user_id'] ?? null, 'connected_at' => now(),
            'data' => [], 'state' => ['running' => true, 'errors' => []],
        ]);
        $conn->save();

        return $conn;
    }

    public function disconnect(TenantGoogleConnection $conn): void
    {
        try {
            if ($conn->refresh_token) {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $conn->refresh_token]);
            }
        } catch (\Throwable) {
            // the row goes either way; the shop can also remove Intake from their Google Account
        }
        $conn->delete();
    }

    public static function allowed(TenantGoogleConnection $conn, string $source): bool
    {
        return str_contains(' ' . $conn->scopes . ' ', ' ' . self::SCOPE[$source] . ' ');
    }

    private function token(TenantGoogleConnection $conn): string
    {
        if ($conn->access_token && $conn->token_expires_at && $conn->token_expires_at->isFuture()) {
            return $conn->access_token;
        }
        $r = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $conn->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if (! $r->ok() || ! $r->json('access_token')) {
            throw new RuntimeException($r->json('error') === 'invalid_grant'
                ? 'Google no longer accepts this sign-in. Connect Google again.'
                : 'Google did not answer. Try again in a few minutes.');
        }
        $conn->access_token = $r->json('access_token');
        $conn->token_expires_at = now()->addSeconds(max(60, (int) $r->json('expires_in', 3600) - 60));
        $conn->save();

        return $conn->access_token;
    }

    private function call(TenantGoogleConnection $conn, string $method, string $url, ?array $json = null): array
    {
        $req = Http::withToken($this->token($conn))->acceptJson()->timeout(25);
        $r = match ($method) {
            'GET' => $req->get($url),
            'POST' => $req->post($url, $json ?? []),
            'PUT' => $req->put($url, $json ?? []),
        };
        if (! $r->successful()) {
            $msg = (string) ($r->json('error.message') ?? '');
            // Intake's own Google Cloud project is not set up for this yet: nothing the shop can do about it
            if (($r->status() === 401 && str_contains($msg, 'not registered with the merchant account'))
                || ($r->status() === 403 && (str_contains($msg, 'has not been used in project') || str_contains($msg, 'is disabled') || str_contains($msg, 'quota') && str_contains($msg, "'0'")))) {
                throw new GoogleSetupNeeded(Str::limit($msg, 400));
            }
            throw new RuntimeException(match (true) {
                $r->status() === 401 => 'Google no longer accepts this sign-in. Connect Google again.',
                $r->status() === 403 => 'This Google account is not allowed to see this.' . ($msg !== '' ? ' (' . Str::limit($msg, 110) . ')' : ''),
                $r->status() === 429 => 'Google is limiting requests right now. Try again later.',
                default => 'Google answered with an error (' . $r->status() . ').' . ($msg !== '' ? ' ' . Str::limit($msg, 110) : ''),
            });
        }

        return (array) ($r->json() ?? []);
    }

    // ------------------------------------------------------------- reading

    /** Everything, source by source. One source's error is kept beside it and the rest carry on. */
    public function sync(Tenant $tenant, TenantGoogleConnection $conn): void
    {
        $st = (array) ($conn->state ?? []);
        $st['running'] = true;
        $st['error'] = null;
        $conn->state = $st;
        $conn->save();

        $data = (array) ($conn->data ?? []);
        $options = (array) ($conn->options ?? []);
        $picks = (array) ($conn->picks ?? []);
        $errors = [];

        foreach (self::sources($tenant) as $src) {
            if (! self::allowed($conn, $src)) {
                unset($data[$src]);
                $errors[$src] = 'Not allowed at sign-in. Connect Google again and leave this ticked.';
                continue;
            }
            try {
                $options[$src] = $this->{'options' . ucfirst($src)}($conn);
                $valid = array_column($options[$src], 'id');
                if (! in_array($picks[$src] ?? null, $valid, true)) {
                    $picks[$src] = $this->autoPick($tenant, $conn, $src, $options[$src]);
                }
                if (! $options[$src]) {
                    unset($data[$src]);
                    $errors[$src] = self::EMPTY[$src];
                    continue;
                }
                if (empty($picks[$src])) {
                    unset($data[$src]);
                    continue; // the shop picks which one; the panel asks
                }
                $data[$src] = $this->{'read' . ucfirst($src)}($tenant, $conn, $picks[$src]) + ['at' => now()->toIso8601String()];
                self::clearSetup($src);
            } catch (GoogleSetupNeeded $e) {
                unset($data[$src]);
                $errors[$src] = self::NOT_READY;
                self::noteSetup($src, $e->getMessage());
            } catch (\Throwable $e) {
                $errors[$src] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not read this from Google.';
                if (! $e instanceof RuntimeException) {
                    report($e);
                }
            }
        }

        $conn->data = $data;
        $conn->options = $options;
        $conn->picks = $picks;
        $conn->state = ['running' => false, 'errors' => $errors, 'error' => null];
        $conn->synced_at = now();
        $conn->save();
    }

    /** What a shop sees when the fault is in Intake's own Google setup. */
    public const NOT_READY = 'Not ready on Intake\'s side yet. Nothing for you to do; it will fill in once it is.';

    /** Keep what Google said for master admin (Tenants > Site health), where it can be acted on. */
    public static function noteSetup(string $src, string $message): void
    {
        $row = PlatformSettings::current();
        $s = (array) ($row->site_health_settings ?? []);
        if ((($s['setup'] ?? [])[$src]['message'] ?? null) === $message) {
            return;
        }
        $s['setup'] = (array) ($s['setup'] ?? []);
        $s['setup'][$src] = ['message' => $message, 'at' => now()->toIso8601String()];
        $row->site_health_settings = $s;
        $row->save();
        PlatformSettings::forget();
    }

    /** A source read cleanly: whatever was wrong with the setup for it is fixed. */
    public static function clearSetup(string $src): void
    {
        $row = PlatformSettings::current();
        $s = (array) ($row->site_health_settings ?? []);
        if (isset($s['setup'][$src])) {
            unset($s['setup'][$src]);
            $row->site_health_settings = $s;
            $row->save();
            PlatformSettings::forget();
        }
    }

    public static function setupProblems(): array
    {
        return (array) ((PlatformSettings::current()->site_health_settings ?? [])['setup'] ?? []);
    }

    private const EMPTY = [
        'gsc' => 'This Google account has no Search Console sites. Add your site at search.google.com/search-console first.',
        'ga'  => 'This Google account has no Analytics properties.',
        'mc'  => 'This Google account has no Merchant Center account.',
        'gbp' => 'This Google account manages no Business Profile.',
    ];

    private function autoPick(Tenant $tenant, TenantGoogleConnection $conn, string $src, array $options): ?string
    {
        if (count($options) === 1) {
            return $options[0]['id'];
        }
        $host = strtolower((string) parse_url($tenant->publicUrl(), PHP_URL_HOST));
        if ($src === 'gsc') {
            foreach ($options as $o) {
                $id = strtolower($o['id']);
                if ($id === 'sc-domain:' . $host || str_contains($id, '//' . $host) || str_contains($id, '//www.' . $host)) {
                    return $o['id'];
                }
            }
        }
        if ($src === 'ga' && ($mid = ShopGa4::id($tenant)) !== '') {
            foreach (array_slice($options, 0, 12) as $o) {
                try {
                    $streams = $this->call($conn, 'GET', 'https://analyticsadmin.googleapis.com/v1beta/' . $o['id'] . '/dataStreams?pageSize=50');
                } catch (\Throwable) {
                    continue;
                }
                foreach ($streams['dataStreams'] ?? [] as $s) {
                    if (strcasecmp((string) ($s['webStreamData']['measurementId'] ?? ''), $mid) === 0) {
                        return $o['id'];
                    }
                }
            }
        }

        return null;
    }

    // -- what the sign-in can see

    private function optionsGsc(TenantGoogleConnection $conn): array
    {
        $r = $this->call($conn, 'GET', 'https://www.googleapis.com/webmasters/v3/sites');
        $out = [];
        foreach ($r['siteEntry'] ?? [] as $s) {
            if (($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
                $out[] = ['id' => (string) $s['siteUrl'], 'name' => preg_replace('#^sc-domain:#', '', (string) $s['siteUrl'])];
            }
        }

        return $out;
    }

    private function optionsGa(TenantGoogleConnection $conn): array
    {
        $r = $this->call($conn, 'GET', 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200');
        $out = [];
        foreach ($r['accountSummaries'] ?? [] as $a) {
            foreach ($a['propertySummaries'] ?? [] as $p) {
                $out[] = ['id' => (string) $p['property'], 'name' => (string) ($p['displayName'] ?? $p['property'])];
            }
        }

        return $out;
    }

    private function optionsMc(TenantGoogleConnection $conn): array
    {
        $r = $this->call($conn, 'GET', 'https://merchantapi.googleapis.com/accounts/v1/accounts?pageSize=100');
        $out = [];
        foreach ($r['accounts'] ?? [] as $a) {
            $out[] = ['id' => (string) $a['name'], 'name' => (string) ($a['accountName'] ?? $a['name'])];
        }

        return $out;
    }

    private function optionsGbp(TenantGoogleConnection $conn): array
    {
        $r = $this->call($conn, 'GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20');
        $out = [];
        foreach (array_slice($r['accounts'] ?? [], 0, 5) as $a) {
            $locs = $this->call($conn, 'GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $a['name'] . '/locations?readMask=name,title,storefrontAddress&pageSize=100');
            foreach ($locs['locations'] ?? [] as $l) {
                $city = $l['storefrontAddress']['locality'] ?? null;
                $out[] = ['id' => $a['name'] . '/' . $l['name'], 'name' => trim(($l['title'] ?? 'Location') . ($city ? ' · ' . $city : ''))];
            }
        }

        return $out;
    }

    // -- the numbers

    private function readGsc(Tenant $tenant, TenantGoogleConnection $conn, string $site): array
    {
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query';
        $end = Carbon::now('UTC')->subDays(2);               // Search Console runs about two days behind
        $d = fn (int $back) => $end->copy()->subDays($back)->toDateString();
        $q = fn (string $from, string $to, array $dims, int $limit) => $this->call($conn, 'POST', $url, ['startDate' => $from, 'endDate' => $to, 'dimensions' => $dims, 'rowLimit' => $limit])['rows'] ?? [];

        $before = [];
        foreach ($q($d(55), $d(28), ['query'], 500) as $r) {
            $before[$r['keys'][0]] = (float) $r['position'];
        }
        // most clicks first, then most often shown: with few clicks Google's own order is no order at all
        $rows = $q($d(27), $d(0), ['query'], 250);
        usort($rows, fn ($a, $b) => [(int) $b['clicks'], (int) ($b['impressions'] ?? 0)] <=> [(int) $a['clicks'], (int) ($a['impressions'] ?? 0)]);
        $queries = [];
        foreach (array_slice($rows, 0, 8) as $r) {
            $was = $before[$r['keys'][0]] ?? null;
            $queries[] = ['q' => (string) $r['keys'][0], 'clicks' => (int) $r['clicks'], 'shown' => (int) ($r['impressions'] ?? 0), 'pos' => (int) round($r['position']), 'move' => $was === null ? null : (int) round($was - $r['position'])];
        }

        $weeks = array_fill(0, 12, 0);
        foreach ($q($d(83), $d(0), ['date'], 100) as $r) {
            $age = (int) Carbon::parse($r['keys'][0], 'UTC')->diffInDays($end);
            $w = 11 - intdiv(max(0, min(83, $age)), 7);
            $weeks[$w] += (int) $r['clicks'];
        }
        $now = array_sum(array_slice($weeks, 8));
        $prev = array_sum(array_slice($weeks, 4, 4));

        $pages = [];
        foreach ($q($d(27), $d(0), ['page'], 500) as $r) {
            $path = '/' . trim((string) (parse_url((string) $r['keys'][0], PHP_URL_PATH) ?: '/'), '/');
            $pages[$path] = ($pages[$path] ?? 0) + (int) $r['clicks'];
        }

        return ['queries' => $queries, 'weeks' => $weeks, 'clicks' => $now, 'clicks_prev' => $prev, 'pages' => $pages];
    }

    private function readGa(Tenant $tenant, TenantGoogleConnection $conn, string $property): array
    {
        $url = 'https://analyticsdata.googleapis.com/v1beta/' . $property . ':runReport';
        $r = $this->call($conn, 'POST', $url, [
            'dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'yesterday']],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 6,
        ]);
        $channels = []; $total = 0;
        foreach ($r['rows'] ?? [] as $row) {
            $n = (int) ($row['metricValues'][0]['value'] ?? 0);
            $channels[] = ['name' => (string) ($row['dimensionValues'][0]['value'] ?? 'Other'), 'n' => $n];
        }
        $t = $this->call($conn, 'POST', $url, ['dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'yesterday']], 'metrics' => [['name' => 'sessions']]]);
        $total = (int) ($t['rows'][0]['metricValues'][0]['value'] ?? array_sum(array_column($channels, 'n')));

        $p = $this->call($conn, 'POST', $url, [
            'dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'yesterday']],
            'dimensions' => [['name' => 'pagePath']], 'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 500,
        ]);
        $pages = [];
        foreach ($p['rows'] ?? [] as $row) {
            $path = '/' . trim((string) (parse_url((string) ($row['dimensionValues'][0]['value'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
            $pages[$path] = ($pages[$path] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0);
        }

        return ['channels' => array_slice($channels, 0, 5), 'sessions' => $total, 'pages' => $pages];
    }

    private function readMc(Tenant $tenant, TenantGoogleConnection $conn, string $account): array
    {
        $url = 'https://merchantapi.googleapis.com/reports/v1/' . $account . '/reports:search';
        $counts = ['showing' => 0, 'limited' => 0, 'rejected' => 0, 'pending' => 0];
        $bad = []; $token = null; $pagesRead = 0;
        do {
            $body = ['query' => 'SELECT offer_id, title, aggregated_reporting_context_status, item_issues FROM product_view', 'pageSize' => 1000];
            if ($token) {
                $body['pageToken'] = $token;
            }
            $r = $this->call($conn, 'POST', $url, $body);
            foreach ($r['results'] ?? [] as $row) {
                $v = (array) ($row['productView'] ?? []);
                $key = match ((string) ($v['aggregatedReportingContextStatus'] ?? '')) {
                    'ELIGIBLE' => 'showing', 'ELIGIBLE_LIMITED' => 'limited', 'NOT_ELIGIBLE_OR_DISAPPROVED' => 'rejected', 'PENDING' => 'pending', default => null,
                };
                if ($key === null) {
                    continue;
                }
                $counts[$key]++;
                if (($key === 'rejected' || $key === 'limited') && count($bad) < 40) {
                    $issue = (array) ($v['itemIssues'][0] ?? []);
                    $code = (string) ($issue['type']['code'] ?? ($issue['type']['canonicalAttribute'] ?? ''));
                    $bad[] = ['offer' => (string) ($v['offerId'] ?? ''), 'title' => Str::limit((string) ($v['title'] ?? 'Item'), 70), 'state' => $key,
                        'why' => $code !== '' ? Str::ucfirst(str_replace(['_', 'n:'], [' ', ''], Str::limit($code, 60, ''))) : null];
                }
            }
            $token = $r['nextPageToken'] ?? null;
        } while ($token && ++$pagesRead < 10);

        usort($bad, fn ($a, $b) => ($a['state'] === 'rejected' ? 0 : 1) <=> ($b['state'] === 'rejected' ? 0 : 1));
        $bad = array_slice($bad, 0, 5);
        // which Intake item each one is, so Fix opens the right editor
        $offers = array_values(array_filter(array_column($bad, 'offer')));
        if ($offers) {
            $byId = TenantInventoryItem::where('tenant_id', $tenant->id)->where(fn ($w) => $w->whereIn('id', $offers)->orWhereIn('sku', $offers))->get(['id', 'sku']);
            foreach ($bad as &$b) {
                $hit = $byId->first(fn ($i) => (string) $i->id === $b['offer'] || (string) $i->sku === $b['offer']);
                $b['item'] = $hit ? (string) $hit->id : null;
            }
            unset($b);
        }

        return ['counts' => $counts, 'items' => $bad, 'more' => (bool) $token];
    }

    private function readGbp(Tenant $tenant, TenantGoogleConnection $conn, string $location): array
    {
        $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $list = []; $token = null; $read = 0; $new = 0; $waiting = 0; $avg = null; $total = 0;
        $monthAgo = now()->subDays(30);
        do {
            $r = $this->call($conn, 'GET', 'https://mybusiness.googleapis.com/v4/' . $location . '/reviews?pageSize=50&orderBy=' . rawurlencode('updateTime desc') . ($token ? '&pageToken=' . rawurlencode($token) : ''));
            $avg ??= isset($r['averageRating']) ? round((float) $r['averageRating'], 1) : null;
            $total = (int) ($r['totalReviewCount'] ?? $total);
            foreach ($r['reviews'] ?? [] as $rev) {
                $n = $stars[$rev['starRating'] ?? ''] ?? 0;
                if ($n) {
                    $dist[$n]++;
                }
                $made = isset($rev['createTime']) ? Carbon::parse($rev['createTime']) : null;
                if ($made && $made->gt($monthAgo)) {
                    $new++;
                }
                $replied = trim((string) ($rev['reviewReply']['comment'] ?? '')) !== '';
                if (! $replied) {
                    $waiting++;
                }
                if (count($list) < 12) {
                    $list[] = [
                        'name' => (string) $rev['name'], 'who' => Str::limit((string) ($rev['reviewer']['displayName'] ?? 'A customer'), 40), 'stars' => $n,
                        'text' => Str::limit(trim((string) ($rev['comment'] ?? '')), 600), 'at' => $made?->toIso8601String(),
                        'reply' => $replied ? Str::limit((string) $rev['reviewReply']['comment'], 600) : null,
                    ];
                }
            }
            $token = $r['nextPageToken'] ?? null;
        } while ($token && ++$read < 4);

        return ['rating' => $avg, 'total' => $total, 'new' => $new, 'waiting' => $waiting, 'dist' => $dist, 'dist_of' => array_sum($dist), 'reviews' => $list];
    }

    /** Post the shop's reply to one review. The review must be one this shop's sign-in listed. */
    public function reply(TenantGoogleConnection $conn, string $reviewName, string $text): void
    {
        $data = (array) ($conn->data ?? []);
        $known = array_column($data['gbp']['reviews'] ?? [], 'name');
        if (! in_array($reviewName, $known, true)) {
            throw new RuntimeException('That review is no longer in the list. Refresh and try again.');
        }
        $this->call($conn, 'PUT', 'https://mybusiness.googleapis.com/v4/' . $reviewName . '/reply', ['comment' => $text]);

        foreach ($data['gbp']['reviews'] as &$r) {
            if ($r['name'] === $reviewName) {
                if ($r['reply'] === null) {
                    $data['gbp']['waiting'] = max(0, (int) $data['gbp']['waiting'] - 1);
                }
                $r['reply'] = $text;
            }
        }
        unset($r);
        $conn->data = $data;
        $conn->save();
    }

    /** Visits a month for a path, from Search Console when it is there, else Analytics. */
    public static function visitsFor(?TenantGoogleConnection $conn): array
    {
        $d = (array) ($conn?->data ?? []);

        return (array) ($d['gsc']['pages'] ?? $d['ga']['pages'] ?? []);
    }
}
