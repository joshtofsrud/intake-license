<?php

namespace App\Console\Commands;

use App\Models\SalesProspect;
use App\Services\Sales\SiteScanner;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * MARKER-SITE-PROBE: a measurement, not a pass. Takes a random sample of
 * shops that have a website but still no email, reads each homepage the way
 * a browser would, and reports which site builder each uses, how many look
 * almost blank to a plain reader, and (for a few) whether a real browser
 * render turns up an email the plain read can't see.
 *
 * Changes nothing: no prospect is written to.
 */
class ProbeProspectSites extends Command
{
    protected $signature = 'prospects:site-probe {--n=300 : shops to sample} {--render=40 : of those, how many to render in a headless browser}';
    protected $description = 'Measure what a headless-browser website pass would add (read-only).';

    private const BUILDERS = [
        'Wix'         => ['static.parastorage.com', 'wix.com', '_wixCssImports', 'wixstatic.com'],
        'Squarespace' => ['static1.squarespace.com', 'squarespace-cdn.com', 'Squarespace'],
        'Shopify'     => ['cdn.shopify.com', 'Shopify.theme'],
        'GoDaddy'     => ['img1.wsimg.com', 'GoDaddy Website Builder', 'godaddysites.com'],
        'Weebly'      => ['weebly.com', 'editmysite.com'],
        'Duda'        => ['irp.cdn-website.com', 'dudaone', 'dmCss'],
        'Webflow'     => ['webflow.com', 'data-wf-site'],
        'WordPress'   => ['wp-content', 'wp-includes'],
        'Lightspeed'  => ['lightspeed', 'webshopapp.com'],
    ];

    public function handle(SiteScanner $scanner): int
    {
        $n = max(10, (int) $this->option('n'));
        $sample = SalesProspect::query()
            ->whereNull('tenant_id')->whereNotNull('website')->where('website', '!=', '')
            ->where(fn ($w) => $w->whereNull('email')->orWhere('email', ''))
            ->whereIn('site_scan_status', ['ok', 'nothing_found', 'unreachable'])
            ->inRandomOrder()->limit($n)->get(['id', 'website', 'site_scan_status']);

        $this->info("Sample: {$sample->count()} shops with a website and no email. Reading homepages…");

        $urls = [];
        foreach ($sample as $p) {
            $u = trim((string) $p->website);
            $urls[$p->id] = str_contains($u, '://') ? $u : 'https://' . $u;
        }

        $pages = [];
        $fail  = [];
        foreach (array_chunk($urls, 25, true) as $chunk) {
            $res = Http::pool(fn (Pool $pool) => array_map(
                fn ($k) => $pool->as((string) $k)->withHeaders([
                    'User-Agent' => SiteScanner::BROWSER_UA,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])->connectTimeout(6)->timeout(15)->get($chunk[$k]),
                array_keys($chunk)
            ));
            foreach (array_keys($chunk) as $k) {
                $r = $res[(string) $k] ?? null;
                $pages[$k] = ($r instanceof \Illuminate\Http\Client\Response && $r->successful()) ? substr((string) $r->body(), 0, 800000) : null;
                $fail[$k]  = $pages[$k] === null ? $r : null;
            }
        }

        $rows = [];
        $why  = [];
        $this->info('Checking why sites didn\'t answer (one at a time, a minute or two)…');
        foreach ($sample as $p) {
            $html = $pages[$p->id] ?? null;
            $host = SiteScanner::hostOf($urls[$p->id]);
            if ($html === null) {
                $rows[$p->id] = ['builder' => '(no answer)', 'blank' => false, 'email' => false, 'text' => 0];
                $why[$p->id]  = $this->whyNoAnswer($urls[$p->id], $host, $fail[$p->id] ?? null);
                continue;
            }
            $text  = $this->visibleText($html);
            if (mb_strlen($text) < 400 && $this->parked($html)) $why[$p->id] = 'Parked or for-sale page (answers, but no shop)';
            $email = $scanner->email($html, $text, $host) ?: $scanner->hiddenEmail($html, $text, $host);
            $rows[$p->id] = ['builder' => $this->builder($html), 'blank' => mb_strlen($text) < 400, 'email' => (bool) $email, 'text' => mb_strlen($text)];
        }

        // the headless render: only where a plain read found no email
        $chrome = $this->chrome();
        $renderN = (int) $this->option('render');
        $rendered = [];
        if ($chrome && $renderN > 0) {
            $pick = collect($rows)->filter(fn ($r) => $r['builder'] !== '(no answer)' && ! $r['email'])
                ->sortByDesc(fn ($r) => $r['blank'])->keys()->take($renderN);
            $this->info("Rendering {$pick->count()} in a headless browser ({$chrome})…");
            foreach ($pick as $id) {
                $proc = new Process([$chrome, '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
                    '--user-agent=' . SiteScanner::BROWSER_UA, '--virtual-time-budget=8000', '--dump-dom', $urls[$id]]);
                $proc->setTimeout(30);
                try { $proc->run(); } catch (\Throwable $e) { continue; }
                $dom = (string) $proc->getOutput();
                if ($dom === '') continue;
                $host = SiteScanner::hostOf($urls[$id]);
                $text = $this->visibleText($dom);
                $rendered[$id] = (bool) ($scanner->email($dom, $text, $host) ?: $scanner->hiddenEmail($dom, $text, $host));
            }
        } elseif (! $chrome) {
            $this->warn('No Chromium/Chrome on this server, so the render step was skipped. The rest of the numbers still stand.');
        }

        // report, one line per builder
        $by = [];
        foreach ($rows as $id => $r) {
            $b = $r['builder'];
            $by[$b] ??= ['shops' => 0, 'blank' => 0, 'email_now' => 0, 'rendered' => 0, 'render_found' => 0];
            $by[$b]['shops']++;
            if ($r['blank']) $by[$b]['blank']++;
            if ($r['email']) $by[$b]['email_now']++;
            if (array_key_exists($id, $rendered)) { $by[$b]['rendered']++; if ($rendered[$id]) $by[$b]['render_found']++; }
        }
        uasort($by, fn ($a, $b) => $b['shops'] <=> $a['shops']);
        $this->table(['Builder', 'Shops', 'Nearly blank to us', 'Email found now (plain read)', 'Rendered', 'Email found after render'],
            collect($by)->map(fn ($v, $k) => [$k, $v['shops'], $v['blank'], $v['email_now'], $v['rendered'], $v['render_found']])->values()->all());

        $tot = count($rows);
        $sum = fn ($k) => array_sum(array_column($by, $k));
        $this->line('');
        $this->line("Of {$tot} sampled: " . $sum('blank') . ' nearly blank to a plain reader; '
            . $sum('email_now') . ' have an email a plain read finds today (their site changed, or the reader improved); '
            . $sum('render_found') . ' of ' . $sum('rendered') . ' rendered turned up an email only a browser could see.');
        // MARKER-SITE-PROBE: why the silent ones are silent, and what would fix it
        if ($why) {
            $this->line('');
            $counts = array_count_values($why);
            arsort($counts);
            $this->table(['Why no usable page', 'Shops', 'What it points to'], collect($counts)->map(fn ($n, $k) => [$k, $n, self::FIX[$k] ?? ''])->values()->all());
        }
        $this->line('Nothing was written to any prospect.');
        return self::SUCCESS;
    }

    private const FIX = [
        'Domain no longer resolves'                       => 'Shop likely closed or moved: flag the prospect',
        'Domain resolves, server refuses or times out'    => 'Dead hosting: likely closed, or try again later',
        'Security certificate broken'                     => 'Retry over plain http',
        'Works on plain http only'                        => 'Retry over plain http',
        'Blocked us (403 / Cloudflare challenge)'         => 'Needs a real browser',
        'Page missing (404/410)'                          => 'Website field is stale: try the bare domain',
        'Server error (5xx)'                              => 'Their site is broken: retry later',
        'Not a web page (PDF, image, file)'               => 'Website field points at a file',
        'Parked or for-sale page (answers, but no shop)'  => 'Shop likely closed: flag the prospect',
        'Other'                                           => '',
    ];

    /** One careful, single read of a site that didn't answer, to say why. */
    private function whyNoAnswer(string $url, string $host, $res): string
    {
        if ($res instanceof \Illuminate\Http\Client\Response) {
            $code = $res->status();
            $body = strtolower(substr((string) $res->body(), 0, 20000));
            if ($res->successful()) return 'Not a web page (PDF, image, file)';
            if (in_array($code, [403, 429, 503], true) && ($res->header('cf-ray') || str_contains($body, 'cloudflare') || str_contains($body, 'captcha') || $code === 403)) return 'Blocked us (403 / Cloudflare challenge)';
            if (in_array($code, [404, 410], true)) return 'Page missing (404/410)';
            if ($code >= 500) return 'Server error (5xx)';
            return 'Other';
        }
        $bare = preg_replace('/^www\./', '', $host);
        if ($host === '' || (! checkdnsrr($host, 'A') && ! checkdnsrr($host, 'AAAA') && ! checkdnsrr($bare, 'A'))) return 'Domain no longer resolves';
        $msg = '';
        try {
            Http::withHeaders(['User-Agent' => SiteScanner::BROWSER_UA])->connectTimeout(8)->timeout(20)->get($url);
        } catch (\Throwable $e) { $msg = $e->getMessage(); }
        if (preg_match('/cURL error (35|51|58|60)\b/', $msg) || stripos($msg, 'SSL') !== false || stripos($msg, 'certificate') !== false) {
            try {
                $r = Http::withHeaders(['User-Agent' => SiteScanner::BROWSER_UA])->connectTimeout(8)->timeout(15)->get('http://' . $host . '/');
                if ($r->successful()) return 'Works on plain http only';
            } catch (\Throwable $e) {}
            return 'Security certificate broken';
        }
        if (preg_match('/cURL error (7|28|52|56)\b/', $msg)) return 'Domain resolves, server refuses or times out';
        if ($msg === '') return 'Other';
        return preg_match('/cURL error 6\b/', $msg) ? 'Domain no longer resolves' : 'Other';
    }

    private function parked(string $html): bool
    {
        return (bool) preg_match('/domain (is|may be) for sale|buy this domain|parked (free|domain)|sedoparking|afternic|dan\.com|hugedomains|this domain has expired|domain has been registered|godaddy\.com\/domainsearch|future home of/i', $html);
    }

    private function visibleText(string $html): string
    {
        $h = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function builder(string $html): string
    {
        foreach (self::BUILDERS as $name => $marks) {
            foreach ($marks as $m) if (stripos($html, $m) !== false) return $name;
        }
        return 'Other / hand-built';
    }

    private function chrome(): ?string
    {
        foreach (['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable'] as $bin) {
            $p = new Process(['which', $bin]);
            $p->run();
            if ($p->isSuccessful() && trim($p->getOutput()) !== '') return trim($p->getOutput());
        }
        return null;
    }
}
