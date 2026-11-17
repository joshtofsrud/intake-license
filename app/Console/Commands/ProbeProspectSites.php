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
            }
        }

        $rows = [];
        foreach ($sample as $p) {
            $html = $pages[$p->id] ?? null;
            $host = SiteScanner::hostOf($urls[$p->id]);
            if ($html === null) { $rows[$p->id] = ['builder' => '(no answer)', 'blank' => false, 'email' => false, 'text' => 0]; continue; }
            $text  = $this->visibleText($html);
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
        $this->line('Nothing was written to any prospect.');
        return self::SUCCESS;
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
