<?php

namespace App\Console\Commands;

use App\Models\SeoCheck as SeoCheckRow;
use App\Models\Tenant;
use App\Support\JobFailureReporter;
use App\Support\Seo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * MARKER-SEO-SIGNALS — visits every live website the way a search engine
 * would and checks the signals are actually there:
 *
 *   sites meant to be found  — robots.txt allows crawling and names the
 *     sitemap; every sitemap file is valid XML and only lists this site's
 *     own addresses; sampled pages load, aren't marked noindex, carry the
 *     right canonical and readable structured data; private pages are
 *     marked noindex; a shop's subdomain points at its custom domain.
 *   sites meant to be hidden — app.intake.works, the demo, suspended or
 *     unpublished shops: robots.txt blocks everything and the home page is
 *     marked noindex (or doesn't load at all).
 *
 * Results land in seo_checks (one row per site per run). Failures go to
 * Issues through JobFailureReporter, one per site. Runs nightly and can be
 * run by hand after a deploy:  php artisan seo:check  [--site=host]
 */
class SeoCheck extends Command
{
    protected $signature = 'seo:check {--site= : only check hosts containing this text}';

    protected $description = 'Check robots, sitemaps, canonicals, noindex and structured data on every live site';

    private const SAMPLE_PAGES    = 20;
    private const SAMPLE_PRODUCTS = 10;

    public function handle(): int
    {
        $sites  = $this->sites();
        $filter = (string) $this->option('site');
        if ($filter !== '') {
            $sites = array_values(array_filter($sites, fn ($s) => str_contains($s['host'], $filter)));
        }

        $rows = [];
        $failed = 0;

        foreach ($sites as $site) {
            $problems = [];
            $checked  = 0;
            try {
                [$problems, $checked] = $site['expect'] === 'indexed'
                    ? $this->checkIndexed($site)
                    : $this->checkHidden($site);
            } catch (\Throwable $e) {
                $problems[] = 'Check crashed: ' . $e->getMessage();
            }

            $status = $problems ? 'fail' : 'ok';
            SeoCheckRow::create([
                'host'         => $site['host'],
                'tenant_id'    => $site['tenant_id'],
                'expect'       => $site['expect'],
                'status'       => $status,
                'problems'     => $problems ?: null,
                'urls_checked' => $checked,
                'checked_at'   => now(),
            ]);

            if ($problems) {
                $failed++;
                JobFailureReporter::report(
                    self::class,
                    'Search check found ' . count($problems) . ' problem(s) on ' . $site['host'],
                    new \RuntimeException(implode("\n", $problems)),
                    ['host' => $site['host'], 'expect' => $site['expect']],
                    $site['tenant_id']
                );
            }

            $rows[] = [$site['host'], $site['expect'] . ($site['why'] ? ' (' . $site['why'] . ')' : ''),
                strtoupper($status), $checked, $problems ? $problems[0] . (count($problems) > 1 ? ' (+' . (count($problems) - 1) . ' more)' : '') : ''];
        }

        $this->table(['Site', 'Expected', 'Result', 'URLs', 'First problem'], $rows);
        $this->line(count($sites) . ' site(s) checked, ' . $failed . ' with problems.');

        SeoCheckRow::where('checked_at', '<', now()->subDays(60))->delete();

        return self::SUCCESS;
    }

    /** Every host we serve, with what search engines should be able to do there. */
    private function sites(): array
    {
        $root  = Seo::rootDomain();
        $sites = [
            ['host' => $root,          'base' => Seo::marketingBase(), 'tenant_id' => null, 'expect' => 'indexed', 'why' => null, 'kind' => 'marketing', 'alias' => null],
            ['host' => 'app.' . $root, 'base' => 'https://app.' . $root, 'tenant_id' => null, 'expect' => 'hidden', 'why' => 'app host', 'kind' => 'app', 'alias' => null],
        ];

        Tenant::query()->orderBy('name')->each(function (Tenant $t) use (&$sites, $root) {
            if ($t->is_platform ?? false) return;
            $base = rtrim($t->publicUrl(), '/');
            $sub  = $t->subdomain ? 'https://' . $t->subdomain . '.' . $root : null;
            $why  = Seo::tenantHiddenReason($t);
            $sites[] = [
                'host'      => parse_url($base, PHP_URL_HOST),
                'base'      => $base,
                'tenant_id' => $t->id,
                'expect'    => $why === null ? 'indexed' : 'hidden',
                'why'       => $why,
                'kind'      => 'tenant',
                'alias'     => ($sub && $sub !== $base) ? $sub : null,
            ];
        });

        return $sites;
    }

    private function checkHidden(array $site): array
    {
        $p = [];
        $n = 0;

        $robots = $this->fetch($site['base'] . '/robots.txt'); $n++;
        if ($robots && $robots->successful() && ! preg_match('/^Disallow:\s*\/\s*$/mi', $robots->body())) {
            $p[] = 'robots.txt does not block the site';
        }

        $home = $this->fetch($site['base'] . '/'); $n++;
        if ($home && $home->status() === 200 && ! $this->noindex($home)) {
            $p[] = 'Home page loads without a noindex signal';
        }

        return [$p, $n];
    }

    private function checkIndexed(array $site): array
    {
        $p    = [];
        $n    = 0;
        $base = $site['base'];

        // robots.txt
        $robots = $this->fetch($base . '/robots.txt'); $n++;
        if (! $robots || ! $robots->successful()) {
            $p[] = 'robots.txt did not load (' . ($robots?->status() ?? 'no response') . ')';
        } else {
            if (preg_match('/^Disallow:\s*\/\s*$/mi', $robots->body())) $p[] = 'robots.txt blocks the whole site';
            if (stripos($robots->body(), 'Sitemap: ' . $base . '/sitemap.xml') === false) $p[] = 'robots.txt does not name ' . $base . '/sitemap.xml';
        }

        // sitemaps
        [$pageUrls, $productUrls, $smProblems, $smCount] = $this->readSitemaps($base . '/sitemap.xml', $base);
        $p = array_merge($p, $smProblems);
        $n += $smCount;
        if (! $pageUrls) $p[] = 'Sitemap lists no pages';

        // sampled pages
        $home = $base . '/';
        $sample = array_slice(array_values(array_unique(array_merge([$home], $pageUrls))), 0, self::SAMPLE_PAGES);
        foreach ($sample as $url) {
            $n++;
            $need = ($url === $home) ? ($site['kind'] === 'marketing' ? 'Organization' : 'LocalBusiness') : null;
            $p = array_merge($p, $this->checkPage($url, $need));
        }
        foreach (array_slice($productUrls, 0, self::SAMPLE_PRODUCTS) as $url) {
            $n++;
            $p = array_merge($p, $this->checkPage($url, 'Product'));
        }

        // a shop's private page must be marked noindex (on intake.works the
        // private areas are Filament panels, kept out by robots.txt instead)
        if ($site['kind'] === 'tenant') {
            $r = $this->fetch($base . '/cart'); $n++;
            if ($r && $r->status() === 200 && ! $this->noindex($r)) {
                $p[] = '/cart loads without a noindex signal';
            }
        }

        // a shop's subdomain must point search engines at its custom domain
        if ($site['alias']) {
            $r = $this->fetch($site['alias'] . '/'); $n++;
            if ($r && $r->status() === 200) {
                $c = $this->canonical($r->body());
                if ($this->norm($c) !== $this->norm($home)) {
                    $p[] = 'Subdomain home canonical is ' . ($c ?: 'missing') . ', expected ' . $home;
                }
            }
        }

        return [$p, $n];
    }

    private function checkPage(string $url, ?string $needType): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $r = $this->fetch($url);
        if (! $r) return [$path . ' did not respond'];
        if ($r->status() !== 200) return [$path . ' returned ' . $r->status()];

        $p = [];
        if ($this->noindex($r)) $p[] = $path . ' is marked noindex';

        $c = $this->canonical($r->body());
        if (! $c) {
            $p[] = $path . ' has no canonical';
        } elseif ($this->norm($c) !== $this->norm($url)) {
            $p[] = $path . ' canonical is ' . $c;
        }

        $types = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $r->body(), $m)) {
            foreach ($m[1] as $json) {
                $d = json_decode(trim($json), true);
                if (! is_array($d)) { $p[] = $path . ' has structured data that does not parse'; continue; }
                $types[] = $d['@type'] ?? null;
            }
        }
        if ($needType && ! in_array($needType, $types, true)) {
            $p[] = $path . ' is missing ' . $needType . ' structured data';
        }

        return $p;
    }

    /** @return array{0: array, 1: array, 2: array, 3: int} page urls, product urls, problems, files fetched */
    private function readSitemaps(string $url, string $base): array
    {
        $pages = []; $products = []; $p = []; $n = 0;

        $queue = [$url];
        while ($queue && $n < 40) {
            $u = array_shift($queue);
            $r = $this->fetch($u); $n++;
            $name = parse_url($u, PHP_URL_PATH);
            if (! $r || ! $r->successful()) { $p[] = $name . ' did not load (' . ($r?->status() ?? 'no response') . ')'; continue; }

            $prev = libxml_use_internal_errors(true);
            $xml  = simplexml_load_string($r->body(), 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if ($xml === false) { $p[] = $name . ' is not valid XML'; continue; }

            if ($xml->getName() === 'sitemapindex') {
                foreach ($xml->sitemap as $s) $queue[] = trim((string) $s->loc);
                continue;
            }

            foreach ($xml->url as $entry) {
                $loc = trim((string) $entry->loc);
                if (! str_starts_with($loc, $base . '/')) { $p[] = $name . ' lists an address on another site: ' . $loc; continue; }
                if (str_contains((string) parse_url($loc, PHP_URL_PATH), '/shop/')) $products[] = $loc; else $pages[] = $loc;
            }
        }

        return [$pages, $products, array_slice(array_values(array_unique($p)), 0, 20), $n];
    }

    private function fetch(string $url)
    {
        try {
            return Http::withHeaders(['User-Agent' => 'IntakeSeoCheck/1.0 (+https://intake.works)'])
                ->timeout(20)
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function noindex($r): bool
    {
        if (stripos((string) $r->header('X-Robots-Tag'), 'noindex') !== false) return true;
        return (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', (string) $r->body());
    }

    private function canonical(string $html): ?string
    {
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html, $m)
            && preg_match('/href=["\']([^"\']+)["\']/i', $m[0], $h)) {
            return html_entity_decode($h[1]);
        }
        return null;
    }

    private function norm(?string $u): string
    {
        return rtrim(strtolower((string) $u), '/');
    }
}
