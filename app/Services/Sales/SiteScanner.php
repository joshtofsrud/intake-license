<?php
// MARKER-SALES-SITE-SCAN — reads a prospect's own website (home page plus up to
// two contact/about pages) and pulls what a shop publishes about itself:
// email, social accounts, an owner when one is named, and the brands it mentions.
// Only fills empty fields; never overwrites something a person typed.

namespace App\Services\Sales;

use App\Models\SalesProspect;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SiteScanner
{
    public const UA = 'Mozilla/5.0 (compatible; IntakeBot/1.0; +https://intake.works)';

    /** Booking tools, listing platforms and the like: a link there is not the shop's own site. */
    public const PLATFORM_HOSTS = ['calendly.com', 'locally.com', 'placeweb.site', 'business.site', 'yelp.com', 'google.com',
        'goo.gl', 'g.page', 'maps.app.goo.gl', 'mapquest.com', 'yellowpages.com', 'bbb.org', 'tripadvisor.com', 'nextdoor.com',
        'squareup.com', 'booksy.com', 'vagaro.com', 'mindbodyonline.com'];

    public const SOCIAL_HOSTS = ['instagram.com' => 'instagram', 'facebook.com' => 'facebook', 'fb.com' => 'facebook',
        'strava.com' => 'strava', 'youtube.com' => 'youtube', 'tiktok.com' => 'tiktok', 'twitter.com' => 'x', 'x.com' => 'x'];

    /** Words that say nothing about which shop this is, for the name check. */
    private const NAME_FILLER = ['bike', 'bikes', 'bicycle', 'bicycles', 'cycle', 'cycles', 'cycling', 'cyclery', 'shop', 'shoppe',
        'the', 'and', 'of', 'co', 'company', 'inc', 'llc', 'ltd', 'outdoor', 'outdoors', 'sports', 'sport', 'store', 'works',
        'center', 'centre', 'repair', 'service', 'services', 'electric', 'ebike', 'ebikes', 'e-bike', 'e-bikes', 'mountain', 'mtb', 'a'];

    /** Capitalised words that are also brand names but mostly appear as ordinary words. */
    private const BRAND_STOP = ['pro', 'kind', 'ride', 'shop', 'bike', 'service', 'home', 'about', 'contact', 'sale', 'new',
        'used', 'more', 'free', 'all', 'one', 'best', 'park', 'origin', 'element', 'spot', 'pure', 'electra electric', 'state',
        'generic', 'misc', 'unknown', 'various', 'universal', 'standard', 'assorted', 'house', 'n/a', 'na', 'none', 'other',
        'sram x', 'go', 'max', 'city', 'trail', 'road', 'gravel', 'mountain', 'tour', 'sport', 'kids', 'youth', 'women',
        'men', 'team', 'club', 'race', 'events', 'rentals', 'repair', 'parts', 'tools', 'accessories', 'apparel', 'brands'];

    public static function isNotShopSite(string $host): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', $host));
        foreach (array_merge(ShopListImporter::NOT_A_SHOP_SITE, self::PLATFORM_HOSTS) as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) return true;
        }
        return false;
    }

    public static function hostOf(string $url): string
    {
        $u = str_contains($url, '://') ? $url : 'https://' . $url;
        return strtolower((string) preg_replace('/^www\./', '', (string) parse_url($u, PHP_URL_HOST)));
    }

    /**
     * Scan a set of prospects together (home pages fetched in parallel).
     * @param  iterable<SalesProspect> $prospects
     * @return array<string,string> prospect id => status
     */
    public function scanMany(iterable $prospects): array
    {
        $todo = []; $out = [];
        foreach ($prospects as $p) {
            $url  = trim((string) $p->website);
            $host = $url !== '' ? self::hostOf($url) : '';
            if ($host === '') { $out[$p->id] = $this->finish($p, 'no_site'); continue; }
            foreach (self::SOCIAL_HOSTS as $sh => $net) {
                if ($host === $sh || str_ends_with($host, '.' . $sh)) {
                    $out[$p->id] = $this->finish($p, 'social_only', ['socials' => [$net => $this->cleanSocial($url)]]);
                    continue 2;
                }
            }
            if (self::isNotShopSite($host)) { $out[$p->id] = $this->finish($p, 'not_shop_site'); continue; }
            $todo[$p->id] = ['p' => $p, 'url' => str_contains($url, '://') ? $url : 'https://' . $url, 'host' => $host];
        }
        if (! $todo) return $out;

        $home = $this->fetchAll(array_map(fn ($t) => $t['url'], $todo));

        $more = [];
        foreach ($todo as $id => $t) {
            if (! isset($home[$id])) continue;
            foreach ($this->subpageLinks($home[$id]['body'], $home[$id]['url']) as $k => $link) $more["$id|$k"] = $link;
        }
        $sub = $more ? $this->fetchAll($more) : [];

        foreach ($todo as $id => $t) {
            if (! isset($home[$id])) { $out[$id] = $this->finish($t['p'], 'unreachable'); continue; }
            $pages = [$home[$id]['body']];
            foreach ($sub as $k => $r) if (str_starts_with($k, "$id|")) $pages[] = $r['body'];
            try {
                $out[$id] = $this->apply($t['p'], $pages, $t['host']);
            } catch (\Throwable $e) {
                report($e);
                $out[$id] = $this->finish($t['p'], 'error');
            }
        }
        return $out;
    }

    /** @param array<string,string> $urls  @return array<string,array{url:string,body:string}> keyed like $urls, failures left out */
    private function fetchAll(array $urls): array
    {
        $keys = array_keys($urls);
        $res = Http::pool(function (Pool $pool) use ($urls) {
            $reqs = [];
            foreach ($urls as $k => $u) {
                $reqs[] = $pool->as((string) $k)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'text/html,*/*;q=0.5'])
                    ->connectTimeout(5)->timeout(12)
                    ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                    ->get($u);
            }
            return $reqs;
        });
        $out = [];
        foreach ($keys as $k) {
            $r = $res[(string) $k] ?? null;
            if (! $r instanceof \Illuminate\Http\Client\Response || ! $r->successful()) continue;
            $type = strtolower((string) $r->header('Content-Type'));
            if ($type !== '' && ! str_contains($type, 'html')) continue;
            $hist = $r->header('X-Guzzle-Redirect-History');
            $final = $hist ? trim(last(explode(',', $hist))) : $urls[$k];
            $out[$k] = ['url' => $final, 'body' => substr((string) $r->body(), 0, 800000)];
        }
        return $out;
    }

    /** Up to two same-site links that look like contact / about pages. */
    private function subpageLinks(string $html, string $base): array
    {
        $host = self::hostOf($base);
        preg_match_all('/<a\b[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER);
        $found = [];
        foreach ($m as $a) {
            $href = html_entity_decode(trim($a[1]));
            $txt  = strtolower(strip_tags($a[2]));
            $key  = strtolower($href) . ' ' . $txt;
            $rank = preg_match('/contact/', $key) ? 0 : (preg_match('/about|our[- ]story|team|staff|who[- ]we/', $key) ? 1 : null);
            if ($rank === null || preg_match('/^(mailto|tel|javascript):/i', $href)) continue;
            $abs = $this->absolute($href, $base);
            if (! $abs || self::hostOf($abs) !== $host) continue;
            if (! isset($found[$rank])) $found[$rank] = $abs;
        }
        ksort($found);
        return array_slice(array_values(array_unique($found)), 0, 2);
    }

    private function absolute(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) return $href;
        $b = parse_url($base);
        if (empty($b['host'])) return null;
        $root = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) return ($b['scheme'] ?? 'https') . ':' . $href;
        if (str_starts_with($href, '/')) return $root . $href;
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $root . $dir . $href;
    }

    /** Pull everything out of the pages and save what's new. */
    private function apply(SalesProspect $p, array $pages, string $host): string
    {
        $html = implode("\n", $pages);
        $text = $this->text($html);

        if (! $this->nameMatches((string) $p->shop, $text . ' ' . $host, (string) $p->city)) {
            return $this->finish($p, 'name_mismatch');
        }

        $email   = $this->email($html, $text, $host);
        $socials = $this->socials($html);
        $owner   = $this->owner($text);
        $brands  = $this->brands($text);

        $changes = ['socials' => $socials ?: null, 'brands' => $brands ?: null];
        if ($email && blank($p->email)) $changes['email'] = $email;
        if ($owner && blank($p->owner_contact)) $changes['owner_contact'] = $owner;

        $found = array_filter([
            isset($changes['email']) ? 'email' : null,
            $socials ? count($socials) . ' social' . (count($socials) === 1 ? '' : 's') : null,
            isset($changes['owner_contact']) ? 'owner' : null,
            $brands ? count($brands) . ' brand' . (count($brands) === 1 ? '' : 's') : null,
        ]);
        $status = ($email || $socials || $owner || $brands) ? 'ok' : 'nothing_found';
        $this->finish($p, $status, $changes);
        if ($found) $p->activities()->create(['type' => 'system', 'body' => 'Website pass found ' . implode(', ', $found)]);
        return $status;
    }

    private function finish(SalesProspect $p, string $status, array $changes = []): string
    {
        $p->forceFill($changes + ['site_scanned_at' => now(), 'site_scan_status' => $status])->save();
        return $status;
    }

    private function text(string $html): string
    {
        $h = preg_replace('#<(script|style|noscript|svg)\b.*?</\1>#is', ' ', $html);
        $h = preg_replace('#</?(br|p|div|li|ul|ol|h[1-6]|tr|td|th|table|section|article|footer|header|nav|main|aside|form|label|button)\b[^>]*>#i', "\n", (string) $h);
        $h = preg_replace('#<[^>]*>#', ' ', (string) $h); // inline tags still separate words
        $t = html_entity_decode((string) $h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $t));
    }

    /** True when a distinctive word of the shop's name appears on the site (or nothing distinctive to check). */
    public function nameMatches(string $shop, string $hay, string $city = ''): bool
    {
        // the city is left out too: "Davis Bike Exchange" must not match "Davis Bike Collective" on the word Davis
        $skip = array_merge(self::NAME_FILLER, preg_split('/[^a-z0-9]+/', strtolower($city)));
        $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($shop)), fn ($w) => strlen($w) >= 3 && ! in_array($w, $skip, true));
        if (! $words) return true;
        $hay = strtolower($hay);
        $squashed = preg_replace('/[^a-z0-9]/', '', $hay);
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/', $hay) || str_contains($squashed, $w)) return true;
        }
        return false;
    }

    public function email(string $html, string $text, string $host): ?string
    {
        $all = [];
        preg_match_all('/mailto:([^"\'?\s>]+)/i', $html, $m);
        foreach ($m[1] as $e) $all[] = urldecode($e);
        // Cloudflare's email protection
        preg_match_all('/data-cfemail="([0-9a-f]+)"/i', $html, $cf);
        foreach ($cf[1] as $hex) {
            $k = hexdec(substr($hex, 0, 2)); $s = '';
            for ($i = 2; $i < strlen($hex); $i += 2) $s .= chr(hexdec(substr($hex, $i, 2)) ^ $k);
            $all[] = $s;
        }
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $t);
        $all = array_merge($all, $t[0]);

        $good = [];
        foreach ($all as $e) {
            $e = strtolower(trim($e, " .,;:<>()[]\"'"));
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            [$local, $dom] = explode('@', $e, 2);
            if (preg_match('/\.(png|jpe?g|gif|webp|svg)$/', $e)) continue;
            if (preg_match('/(^|\.)(sentry\.io|wixpress\.com|example\.com|domain\.com|email\.com|yourdomain\.com|yoursite\.com|sentry-next\.wixpress\.com|godaddy\.com|squarespace\.com|shopify\.com)$/', $dom)) continue;
            if (preg_match('/^(no-?reply|donotreply|privacy|abuse|webmaster)$/', $local)) continue;
            $good[] = $e;
        }
        if (! $good) return null;
        $root = implode('.', array_slice(explode('.', $host), -2));
        foreach ($good as $e) if (str_ends_with($e, '@' . $root) || str_ends_with($e, '.' . $root)) return mb_substr($e, 0, 191);
        return mb_substr($good[0], 0, 191);
    }

    public function socials(string $html): array
    {
        preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', $html, $m);
        $out = [];
        foreach ($m[1] as $u) {
            $u = html_entity_decode($u);
            $host = self::hostOf($u);
            foreach (self::SOCIAL_HOSTS as $sh => $net) {
                if (isset($out[$net]) || ! ($host === $sh || str_ends_with($host, '.' . $sh))) continue;
                $path = trim((string) parse_url($u, PHP_URL_PATH), '/');
                if ($path === '' || preg_match('#^(sharer|share|intent|dialog|plugins|tr|hashtag|p|reel|reels|watch|embed|explore|home|login|groups/[^/]*/permalink)(/|\.php|$)#i', $path)) continue;
                if ($net === 'strava' && ! str_starts_with($path, 'clubs/')) continue;
                $out[$net] = $this->cleanSocial($u);
            }
        }
        return $out;
    }

    private function cleanSocial(string $u): string
    {
        $u = preg_replace('/[?#].*$/', '', $u);
        return mb_substr(rtrim((string) $u, '/'), 0, 255);
    }

    public function owner(string $text): ?string
    {
        $name = "([A-Z][a-z]+(?: [A-Z]\\.)?(?: (?:Mc|Mac|O')?[A-Z][a-zA-Z'\\-]+){1,2})";
        $role = '(?i:co-?owner|owner|co-?founder|founder|proprietor)s?';
        $pats = [
            "/\\b{$role}\\b(?: and [a-z ]+)?[\\s:,\\-–—|]+(?:is\\s+)?{$name}/iu",
            "/{$name}\\s*[,\\-–—|(]\\s*(?:the\\s+)?{$role}\\b/u",
            "/{$name}\\s+(?i:is the|is our|, our)\\s+{$role}\\b/u",
            "/(?i:owned|founded|run) by {$name}/u",
        ];
        $bad = '/\b(bike|bikes|bicycle|cycle|cycles|shop|street|road|avenue|our|the|contact|about|team|hours|service|store|llc|inc|company|repair|sales|parts|welcome|story|manual|login|account|portal|resources|reviews|page|info)\b/i';
        foreach ($pats as $re) {
            if (! preg_match_all($re, $text, $m)) continue;
            foreach ($m[1] as $n) {
                $n = trim($n);
                if (preg_match($bad, $n) || str_word_count($n) < 2 || mb_strlen($n) > 40) continue;
                return $n;
            }
        }
        return null;
    }

    /** Brands from the distributor catalogs that the site mentions, most-mentioned first. */
    public function brands(string $text): array
    {
        $dict = self::brandDictionary();
        if (! $dict) return [];
        $words = preg_split('/[^\p{L}\p{N}&\'\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $hits = [];
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            for ($len = 3; $len >= 1; $len--) {
                if ($i + $len > $n) continue;
                $first = $words[$i];
                if (! preg_match('/^\p{Lu}/u', $first) && ! preg_match('/^\p{N}/u', $first)) continue; // brand names are written capitalised
                $phrase = mb_strtolower(implode(' ', array_slice($words, $i, $len)));
                if (isset($dict[$phrase])) { $hits[$dict[$phrase]] = ($hits[$dict[$phrase]] ?? 0) + 1; $i += $len - 1; break; }
            }
        }
        arsort($hits);
        return array_slice(array_keys($hits), 0, 40);
    }

    /** lowercase name => display name, from manufacturers carried by the distributor catalogs (cached a day). */
    public static function brandDictionary(): array
    {
        $hit = Cache::get('sales:site-scan:brands');
        if (is_array($hit) && $hit) return $hit;
        $dict = (function () {
            try {
                $rows = DB::table('platform_distributor_catalogs')
                    ->whereNotNull('manufacturer')->where('manufacturer', '!=', '')
                    ->select('manufacturer', DB::raw('COUNT(*) n'))->groupBy('manufacturer')
                    ->having('n', '>=', 10)->pluck('manufacturer');
            } catch (\Throwable $e) {
                return [];
            }
            $out = [];
            foreach ($rows as $name) {
                $name = trim(preg_replace('/\s+/', ' ', (string) $name));
                $key = mb_strtolower($name);
                if (mb_strlen($key) < 3 || in_array($key, self::BRAND_STOP, true) || substr_count($key, ' ') > 2) continue;
                if ($name === mb_strtoupper($name) && mb_strlen($name) > 4) $name = ucwords(mb_strtolower($name)); // SHIMANO → Shimano
                $out[$key] ??= $name;
            }
            return $out;
        })();
        if ($dict) Cache::put('sales:site-scan:brands', $dict, 86400); // an empty list (catalog unreachable) is not kept
        return $dict;
    }
}
