<?php

namespace App\Services\Distributors;

use App\Support\JobFailureReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * J&B Importers (JBI) adapter.
 *
 *   Catalog : four tab-delimited files on ftp.jbi.bike in inv/v2 —
 *             inv_mast (identity, price, size, flags), inv_bullets (categories
 *             and marketing bullets), inv_spec (label/value pairs) and
 *             inv_loc (stock per warehouse). Docs: api.jbi.bike/docs.
 *   Stock   : the v3 inventory API (200 part numbers a call, live at the
 *             moment of asking). inv_loc is meant to refresh every 15
 *             minutes but has gone days without changing, so it is the
 *             fallback, and the only source for warehouse 111 (Florida
 *             Closeout), which the API does not report.
 *
 * Like Shimano, the files are fetched once, joined on part number and kept
 * as JSON lines, so a shop's stock refresh never re-reads the FTP.
 *
 * Credentials ride in the api_key slot as JSON:
 *   {"u": FTP user, "p": FTP password, "a": account number, "t": API token}
 * The API token and account are optional: without them stock comes from the
 * file. A shop's account number on its subscription fills "a" when blank.
 *
 * Identifiers: upc_ean is one column holding both kinds, split here by digit
 * count (12 = UPC, 13 = EAN). supplier_part_no is the manufacturer's part
 * number, brand-qualified by the identifier service. JBI has no product
 * group, so every part number is its own product (product_code = item_id).
 */
class JbiClient implements DistributorAdapter
{
    public const FILES = ['inv_mast.txt', 'inv_bullets.txt', 'inv_spec.txt', 'inv_loc.txt'];

    /** warehouse code => [API name, display name] */
    public const WAREHOUSES = [
        '101' => ['FL', 'Florida'],
        '102' => ['NC', 'North Carolina'],
        '103' => ['NY', 'New York'],
        '104' => ['AL', 'Alabama'],
        '105' => ['IN', 'Indiana'],
        '106' => ['PA', 'Pennsylvania'],
        '107' => ['MN', 'Minnesota'],
        '108' => ['TX', 'Texas'],
        '109' => ['CO', 'Colorado'],
        '110' => ['WA', 'Washington'],
        '111' => ['FCL', 'Florida Closeout'],
        '112' => ['CA', 'California'],
    ];
    public const CLOSEOUT = '111';

    private string $host;
    private string $dir;
    private string $apiBase;
    private int $timeout;
    private int $catalogMinutes;
    private int $stockMinutes;
    private int $staleHours;
    private int $batch;
    private string $cacheDir;

    private string $user = '';
    private string $pass = '';
    private string $account = '';
    private string $token = '';

    /** the shop counts warehouse 111 (off unless switched on in its box) */
    private bool $closeout = false;

    private bool $refreshedThisRun = false;

    /** where the last inventory() call got its numbers: api | file | mixed */
    public string $lastStockSource = '';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region = 'us',
        ?array $config = null,
    ) {
        $cfg = $config ?? config('distributors.jbi', []);
        $this->host           = (string) ($cfg['ftp_host'] ?? 'ftp.jbi.bike');
        $this->dir            = trim((string) ($cfg['dir'] ?? 'inv/v2'), '/');
        $this->apiBase        = rtrim((string) ($cfg['api_base'] ?? 'https://api.jbi.bike'), '/');
        $this->timeout        = (int) ($cfg['timeout'] ?? 120);
        $this->catalogMinutes = (int) ($cfg['catalog_minutes'] ?? 360);
        $this->stockMinutes   = (int) ($cfg['stock_minutes'] ?? 15);
        $this->staleHours     = (int) ($cfg['stale_hours'] ?? 6);
        $this->batch          = max(1, min(200, (int) ($cfg['api_batch'] ?? 200)));
        $this->cacheDir       = (string) ($cfg['cache_dir'] ?? storage_path('app/distributor-cache'));

        $c = self::unpack($apiKey);
        $this->user    = $c['u'];
        $this->pass    = $c['p'];
        $this->account = $c['a'];
        $this->token   = $c['t'];
    }

    /** @return array{u:string,p:string,a:string,t:string} */
    public static function unpack(?string $stored): array
    {
        $j = json_decode((string) $stored, true);
        $j = is_array($j) ? $j : [];
        return [
            'u' => trim((string) ($j['u'] ?? '')),
            'p' => (string) ($j['p'] ?? ''),
            'a' => trim((string) ($j['a'] ?? '')),
            't' => trim((string) ($j['t'] ?? '')),
        ];
    }

    public static function pack(array $c): string
    {
        return json_encode([
            'u' => (string) ($c['u'] ?? ''), 'p' => (string) ($c['p'] ?? ''),
            'a' => (string) ($c['a'] ?? ''), 't' => (string) ($c['t'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Shop-level settings the registry hands over after construction: the
     * subscription's account number and the shop's closeout switch.
     */
    public function setOptions(array $opts): static
    {
        if ($this->account === '' && filled($opts['account_number'] ?? null)) {
            $this->account = trim((string) $opts['account_number']);
        }
        $this->closeout = (bool) ($opts['options']['include_closeout'] ?? false);
        return $this;
    }

    public function code(): string { return 'JBI'; }
    public function name(): string { return 'J&B Importers'; }

    // ------------------------------------------------------------- probe

    public function testConnection(): array
    {
        $lines = [];
        $ok = true;

        try {
            $ftp = $this->connect();
            $found = [];
            foreach (self::FILES as $f) {
                $t = @ftp_mdtm($ftp, $this->dir . '/' . $f);
                $found[] = $t > 0 ? $f . ' ' . $this->age($t) : $f . ' missing';
                if ($t <= 0) { $ok = false; }
            }
            ftp_close($ftp);
            $lines[] = 'FTP: ' . implode(', ', $found) . '.';
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'auth' => str_contains($e->getMessage(), 'login refused'),
                    'body' => 'FTP: ' . $e->getMessage()];
        }

        if ($this->token === '' || $this->account === '') {
            $lines[] = 'API: no ' . ($this->token === '' ? 'token' : 'account number') . ' saved, so stock comes from inv_loc.txt.';
        } else {
            try {
                $r = $this->http()->post($this->apiUrl(), ['__test__']);
                if ($r->status() === 401) {
                    $ok = false;
                    $lines[] = 'API: token refused for account ' . $this->account . '.';
                } elseif ($r->successful()) {
                    $lines[] = 'API: token accepted for account ' . $this->account . '; live stock on.';
                } else {
                    $ok = false;
                    $lines[] = 'API: answered ' . $r->status() . ' ' . ($r->json('error.message') ?? '');
                }
            } catch (\Throwable $e) {
                $ok = false;
                $lines[] = 'API: ' . $e->getMessage();
            }
        }

        if ($st = $this->feedStats()) {
            $lines[] = 'Last catalog build: ' . number_format((int) $st['products']) . ' products'
                . ((int) $st['unlabelled_specs'] > 0
                    ? ', ' . number_format((int) $st['unlabelled_specs']) . ' spec values skipped for having no label.'
                    : ', every spec value labelled.');
        }

        return ['ok' => $ok, 'status' => $ok ? 200 : 0, 'body' => implode(' ', $lines)];
    }

    /** What the last catalog build found, for the test message and sync results. */
    public function feedStats(): ?array
    {
        $s = Cache::get('jbi.feed_stats.' . $this->slug());
        return is_array($s) ? $s : null;
    }

    private function age(int $ts): string
    {
        $h = (time() - $ts) / 3600;
        return $h < 1 ? 'updated ' . max(1, (int) round($h * 60)) . ' min ago'
            : ($h < 48 ? 'updated ' . (int) round($h) . ' h ago' : 'updated ' . (int) round($h / 24) . ' days ago');
    }

    // --------------------------------------------------------- transport

    /** @return resource|\FTP\Connection */
    private function connect()
    {
        if ($this->user === '' || $this->pass === '') {
            throw new \RuntimeException('no FTP username and password saved');
        }
        if (! function_exists('ftp_connect')) {
            throw new \RuntimeException('PHP on this server has no FTP extension');
        }
        $ftp = @ftp_connect($this->host, 21, 30);
        if (! $ftp) {
            throw new \RuntimeException("could not reach {$this->host}");
        }
        if (! @ftp_login($ftp, $this->user, $this->pass)) {
            ftp_close($ftp);
            throw new \RuntimeException('login refused');
        }
        ftp_pasv($ftp, true);
        return $ftp;
    }

    private function work(): string
    {
        $d = rtrim($this->cacheDir, '/') . '/' . $this->slug() . '-files';
        if (! is_dir($d)) { @mkdir($d, 0775, true); }
        return $d;
    }

    /** Download the named files; returns name => [local path, remote mtime]. */
    private function download(array $names): array
    {
        $ftp = $this->connect();
        $out = [];
        try {
            foreach ($names as $n) {
                $local = $this->work() . '/' . $n;
                $tmp = $local . '.part';
                if (! @ftp_get($ftp, $tmp, $this->dir . '/' . $n, FTP_BINARY) || filesize($tmp) < 64) {
                    @unlink($tmp);
                    throw new \RuntimeException("JBI: {$n} did not download");
                }
                rename($tmp, $local);
                $out[$n] = [$local, (int) @ftp_mdtm($ftp, $this->dir . '/' . $n)];
            }
        } finally {
            ftp_close($ftp);
        }
        return $out;
    }

    /**
     * Rows of one tab-delimited file as header => value. JBI swaps quote
     * marks for backticks; that is undone by the field map's clean-up in
     * master admin, not here, so the raw value stays visible.
     *
     * @return \Generator<int,array<string,string>>
     */
    private function read(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) { return; }
        try {
            $head = fgets($fh);
            if ($head === false) { return; }
            $head = explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $head), "\r\n"));
            $w = count($head);
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') { continue; }
                $cells = explode("\t", $line);
                if (count($cells) < $w) { $cells = array_pad($cells, $w, ''); }
                $row = array_combine($head, array_slice($cells, 0, $w));
                yield array_map('trim', $row);
            }
        } finally {
            fclose($fh);
        }
    }

    // ------------------------------------------------------------- cache

    /**
     * Each JBI login sees its own dealer price, so every cached file is kept
     * per FTP user: a shop's copy never serves another shop's prices.
     */
    private function slug(): string { return 'jbi-' . substr(hash('sha256', strtolower($this->user)), 0, 12); }
    private function catalogPath(): string { return rtrim($this->cacheDir, '/') . '/' . $this->slug() . '.jsonl'; }
    private function stockPath(): string   { return rtrim($this->cacheDir, '/') . '/' . $this->slug() . '-stock.json'; }

    private function catalog(bool $force = false): string
    {
        $path = $this->catalogPath();
        $fresh = is_file($path) && filesize($path) > 0 && (time() - filemtime($path)) < $this->catalogMinutes * 60;
        if (($force && ! $this->refreshedThisRun) || ! $fresh) {
            try {
                $this->rebuild($path);
                $this->refreshedThisRun = true;
            } catch (\Throwable $e) {
                if ($force || ! is_file($path) || filesize($path) === 0) { throw $e; }
            }
        }
        return $path;
    }

    private function rebuild(string $path): void
    {
        if (! is_dir($this->cacheDir)) { @mkdir($this->cacheDir, 0775, true); }
        $this->buildFrom($this->download(self::FILES), $path);
    }

    /**
     * Join the four downloaded files into the JSON-lines catalog.
     *
     * @param array<string,array{0:string,1:int}> $files name => [local path, remote mtime]
     */
    public function buildFrom(array $files, string $path): void
    {

        // bullets and specs are joined in memory (about 10 MB between them);
        // the master file is streamed.
        $bullets = [];
        foreach ($this->read($files['inv_bullets.txt'][0]) as $r) {
            $id = (string) ($r['item_id'] ?? '');
            if ($id === '') { continue; }
            $model = []; $sku = [];
            for ($i = 1; $i <= 20; $i++) {
                if (($v = $r["model_bullet_{$i}"] ?? '') !== '') { $model[] = $v; }
                if (($v = $r["product_bullet_{$i}"] ?? '') !== '') { $sku[] = $v; }
            }
            $cats = array_values(array_filter([$r['cat_1'] ?? '', $r['cat_2'] ?? '', $r['cat_3'] ?? '', $r['cat_4'] ?? ''], fn ($c) => $c !== ''));
            $bullets[$id] = ['c' => $cats, 'm' => $model, 's' => $sku];
        }

        $specs = [];
        $unlabelled = 0;
        foreach ($this->read($files['inv_spec.txt'][0]) as $r) {
            $id = (string) ($r['part_number'] ?? '');
            if ($id === '') { continue; }
            $pairs = [];
            for ($i = 1; $i <= 20; $i++) {
                $t = $r["title_{$i}"] ?? '';
                $v = $r["spec_{$i}"] ?? '';
                if ($v === '') { continue; }
                if ($t === '') { $unlabelled++; continue; }   // a value with no label means nothing to a shop
                $pairs[] = ['Name' => $t, 'Value' => $v];
            }
            if ($pairs) { $specs[$id] = $pairs; }
        }

        $tmp = $path . '.part';
        $out = fopen($tmp, 'w');
        if ($out === false) { throw new \RuntimeException('JBI: cannot write ' . $tmp); }

        $count = 0;
        try {
            foreach ($this->read($files['inv_mast.txt'][0]) as $r) {
                $rec = $this->normalize($r, $bullets[$r['item_id'] ?? ''] ?? null, $specs[$r['item_id'] ?? ''] ?? []);
                if ($rec === null) { continue; }
                fwrite($out, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                $count++;
            }
        } finally {
            fclose($out);
        }
        if ($count === 0) {
            @unlink($tmp);
            throw new \RuntimeException('JBI: inv_mast.txt held no rows');
        }
        rename($tmp, $path);

        // the stock file came down with the rest; keep it as the fallback
        $this->writeStock($files['inv_loc.txt'][0], $files['inv_loc.txt'][1]);

        Cache::put('jbi.feed_stats.' . $this->slug(), [
            'products' => $count, 'unlabelled_specs' => $unlabelled,
            'built_at' => now()->toIso8601String(),
            'files' => array_map(fn ($f) => $f[1] ? date('c', $f[1]) : null, $files),
        ], now()->addDays(7));

        foreach ($files as [$local]) { @unlink($local); }
    }

    private function yn(string $v): bool { return strtoupper(trim($v)) === 'Y'; }

    private function num(string $v): ?float
    {
        $v = trim($v);
        return ($v === '' || ! is_numeric($v)) ? null : (float) $v;
    }

    /** @return array{0:string,1:string} [upc, ean] */
    private function barcode(string $raw): array
    {
        $d = preg_replace('/\D+/', '', $raw);
        return match (strlen($d)) {
            12 => [$d, ''],
            13 => ['', $d],
            default => [$d !== '' && strlen($d) < 12 ? $d : '', strlen($d) > 13 ? $d : ''],
        };
    }

    private function normalize(array $r, ?array $b, array $specs): ?array
    {
        $id = (string) ($r['item_id'] ?? '');
        if ($id === '') { return null; }

        [$upc, $ean] = $this->barcode($r['upc_ean'] ?? '');

        $cats = $b['c'] ?? [];
        $lines = array_values(array_unique(array_merge($b['m'] ?? [], $b['s'] ?? [])));

        $dims = array_values(array_filter([
            $this->num($r['length'] ?? ''), $this->num($r['width'] ?? ''), $this->num($r['height'] ?? ''),
        ], fn ($d) => $d !== null && $d > 0));

        $created = null;
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', (string) ($r['date_created'] ?? ''), $m)) {
            $created = "{$m[3]}-{$m[1]}-{$m[2]}";
        }

        $map = $this->num($r['map'] ?? '');
        $limit = (int) ($this->num($r['qty_restriction'] ?? '') ?? 0);

        $out = [
            'item_id'           => $id,
            'product_code'      => $id,
            'brand'             => (string) ($r['brand'] ?? ''),
            'model'             => (string) ($r['model'] ?? ''),
            'name'              => (string) ($r['description'] ?? ''),
            'upc'               => $upc,
            'ean'               => $ean,
            'mpn'               => (string) ($r['supplier_part_no'] ?? ''),
            'category'          => $cats ? end($cats) : '',
            'category_path'     => implode(' > ', $cats),
            'category_top'      => $cats[0] ?? '',
            'description'       => implode("\n", $lines),
            'bullets'           => $lines,
            'attrs'             => $specs,
            'case_qty'          => (int) ($this->num($r['case_qty'] ?? '') ?? 0) ?: null,
            'uom'               => (string) ($r['uom'] ?? ''),
            'cost'              => $this->num($r['price'] ?? ''),
            'msrp'              => $this->num($r['msrp'] ?? ''),
            'map_price'         => ($map !== null && $map > 0) ? $map : null,
            'weight'            => $this->num($r['weight'] ?? ''),
            'dimensions'        => $dims,
            'cube'              => $this->num($r['cube'] ?? ''),
            'hazmat'            => $this->yn($r['ormd'] ?? '') ? 'ORM-D / Limited Quantity' : '',
            'prop65'            => (string) ($r['warning_label'] ?? ''),
            'created'           => $created,
            'restricted_online' => $this->yn($r['restricted_online'] ?? ''),
            'dropship'          => $this->yn($r['dropshipable'] ?? ''),
            'no_freight_allow'  => $this->yn($r['nfa'] ?? ''),
            'qty_limit'         => $limit > 0 ? $limit : null,
            'qty_limit_period'  => $limit > 0 ? (string) ($r['qty_restriction_period'] ?? '') : null,
            'status'            => 'active',   // the file holds active SKUs only
        ];

        return array_filter($out, fn ($v) => $v !== '' && $v !== null && $v !== []);
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function rows(bool $force = false): \Generator
    {
        $fh = fopen($this->catalog($force), 'r');
        if ($fh === false) { return; }
        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) { yield $row; }
            }
        } finally {
            fclose($fh);
        }
    }

    // ------------------------------------------------------------- stock

    private function writeStock(string $local, int $mtime): void
    {
        $map = [];
        foreach ($this->read($local) as $r) {
            $id = (string) ($r['item_id'] ?? '');
            if ($id === '') { continue; }
            $w = [];
            foreach (array_keys(self::WAREHOUSES) as $code) {
                $w[$code] = (int) ($r["avail_{$code}"] ?? 0);
            }
            $map[$id] = $w;
        }
        $tmp = $this->stockPath() . '.part';
        file_put_contents($tmp, json_encode(['mtime' => $mtime, 'fetched' => time(), 'items' => $map]));
        rename($tmp, $this->stockPath());
    }

    /** @return array{mtime:int,items:array<string,array<string,int>>} */
    private function stockFile(): array
    {
        $path = $this->stockPath();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $fetched = (int) ($data['fetched'] ?? 0);

        if (! is_array($data) || time() - $fetched > $this->stockMinutes * 60) {
            try {
                $f = $this->download(['inv_loc.txt']);
                $this->writeStock($f['inv_loc.txt'][0], $f['inv_loc.txt'][1]);
                @unlink($f['inv_loc.txt'][0]);
                $data = json_decode((string) file_get_contents($path), true);
            } catch (\Throwable $e) {
                if (! is_array($data)) { throw $e; }
            }
        }

        $mtime = (int) ($data['mtime'] ?? 0);
        if ($mtime > 0 && time() - $mtime > $this->staleHours * 3600
            && Cache::add('jbi.stale_stock_reported', 1, now()->addDay())) {
            JobFailureReporter::report(self::class,
                'JBI stock file inv_loc.txt was last updated ' . $this->age($mtime) . ' (JBI says every 15 minutes)',
                new \RuntimeException('JBI inv_loc.txt is stale'),
                ['file_updated' => date('c', $mtime)]);
        }
        return ['mtime' => $mtime, 'items' => (array) ($data['items'] ?? [])];
    }

    private function http()
    {
        return Http::withHeaders(['Token-key' => $this->token, 'Accept' => 'application/json'])
            ->timeout($this->timeout)->retry(2, 500, throw: false);
    }

    private function apiUrl(): string
    {
        return $this->apiBase . '/customer/v3/inventory/' . rawurlencode($this->account);
    }

    /** @return array<string,array<string,int>>|null part => code => qty, null when the call failed */
    private function apiStock(array $parts): ?array
    {
        $r = $this->http()->post($this->apiUrl(), array_values($parts));
        if (! $r->successful() || $r->json('success') !== true) {
            return null;
        }
        $byName = [];
        foreach (self::WAREHOUSES as $code => [$api]) { $byName[$api] = $code; }

        $out = [];
        foreach ((array) $r->json('data') as $row) {
            $pn = (string) ($row['partNumber'] ?? '');
            if ($pn === '') { continue; }
            $w = [];
            foreach ((array) ($row['locations'] ?? []) as $loc) {
                $code = $byName[strtoupper((string) ($loc['name'] ?? ''))] ?? null;
                if ($code !== null) { $w[$code] = (int) ($loc['quantity'] ?? 0); }
            }
            $out[$pn] = $w;
        }
        return $out;
    }

    /**
     * Stock per warehouse. An empty list means every part number in the feed
     * (the velocity sweep's whole-catalog read).
     */
    public function inventory(array $skus): array
    {
        $parts = array_values(array_unique(array_map('strval', $skus)));
        if (! $parts) {
            foreach ($this->rows() as $r) { $parts[] = (string) $r['item_id']; }
        }

        $live = [];
        $failed = $parts;
        if ($this->token !== '' && $this->account !== '') {
            $failed = [];
            foreach (array_chunk($parts, $this->batch) as $chunk) {
                $got = null;
                try { $got = $this->apiStock($chunk); } catch (\Throwable) { $got = null; }
                if ($got === null) { array_push($failed, ...$chunk); continue; }
                $live += $got;
                // JBI says every part asked for comes back; any that does not
                // is read from the file rather than dropped
                foreach ($chunk as $pn) {
                    if (! isset($got[$pn])) { $failed[] = $pn; }
                }
            }
        }

        $file = ($failed || $this->closeout) ? $this->stockFile()['items'] : [];
        $this->lastStockSource = $live && $failed ? 'mixed' : ($live ? 'api' : 'file');
        $failedSet = array_flip($failed);

        $out = [];
        foreach ($parts as $pn) {
            $w = isset($failedSet[$pn]) ? ($file[$pn] ?? null) : ($live[$pn] ?? null);
            if ($w === null) { continue; }
            unset($w[self::CLOSEOUT]);
            if ($this->closeout) {
                $w[self::CLOSEOUT] = (int) ($file[$pn][self::CLOSEOUT] ?? 0);
            }
            ksort($w);
            $wh = [];
            foreach ($w as $code => $q) {
                $wh[] = ['Code' => (string) $code, 'Name' => self::WAREHOUSES[$code][1] ?? (string) $code, 'QtyAvailable' => max(0, (int) $q)];
            }
            $out[] = ['VariantNo' => $pn, 'TotalQtyAvailable' => array_sum(array_column($wh, 'QtyAvailable')), 'Warehouses' => $wh];
        }
        return $out;
    }

    // -------------------------------------------------------------- api

    public function brands(): array
    {
        $out = [];
        foreach ($this->rows() as $r) {
            $b = (string) ($r['brand'] ?? '');
            if ($b !== '' && ! isset($out[$b])) { $out[$b] = ['id' => $b, 'name' => $b]; }
        }
        ksort($out);
        return array_values($out);
    }

    public function categories(): array
    {
        $out = [];
        foreach ($this->rows() as $r) {
            $p = (string) ($r['category_path'] ?? '');
            if ($p === '' || isset($out[$p])) { continue; }
            $out[$p] = ['id' => $p, 'name' => (string) ($r['category'] ?? $p), 'parent_id' => '', 'path' => $p];
        }
        return array_values($out);
    }

    /**
     * HLC's nested shape, one product per part number. A whole-catalog call
     * (no filters) is a catalog sync and refreshes the files from JBI first.
     *
     * @param array{brands?: array, upcs?: array, vendorParts?: array, pageStartIndex?: int, pageSize?: int} $opts
     */
    public function products(array $opts = []): array
    {
        $brands = array_flip(array_map(fn ($b) => mb_strtolower(trim((string) $b)), $opts['brands'] ?? []));
        $upcs   = array_flip(array_map('strval', $opts['upcs'] ?? []));
        $parts  = array_flip(array_map(fn ($p) => strtoupper(trim((string) $p)), $opts['vendorParts'] ?? []));
        $filtered = $upcs || $parts || $brands;

        $start = max(0, ((int) ($opts['pageStartIndex'] ?? 1)) - 1);
        $size  = (int) ($opts['pageSize'] ?? 0);

        $out = [];
        $i = -1;
        foreach ($this->rows(! $filtered) as $r) {
            if ($brands && ! isset($brands[mb_strtolower((string) ($r['brand'] ?? ''))])) { continue; }
            if ($upcs || $parts) {
                $hitUpc  = $upcs && (isset($upcs[$r['upc'] ?? '']) || isset($upcs[$r['ean'] ?? '']));
                $hitPart = $parts && (isset($parts[strtoupper((string) $r['item_id'])]) || isset($parts[strtoupper((string) ($r['mpn'] ?? ''))]));
                if (! $hitUpc && ! $hitPart) { continue; }
            }
            $i++;
            if ($size > 0 && ($i < $start || $i >= $start + $size)) { continue; }

            $r['VariantNo'] = $r['item_id'];
            $out[] = [
                'Brand'        => ($r['brand'] ?? '') !== '' ? $r['brand'] : 'Unknown',
                'product_code' => $r['item_id'],
                'Name'         => $r['name'] ?? '',
                'Variants'     => [$r],
            ];
        }
        return ['Products' => $out, 'Failures' => []];
    }

    public function prices(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];
        foreach ($this->rows() as $r) {
            $id = (string) $r['item_id'];
            if ($want && ! isset($want[$id])) { continue; }
            $out[] = array_merge($r, [
                'VariantNo' => $id,
                'Prices'    => [['PriceTypeId' => 1, 'PriceType' => 'Base', 'Price' => (float) ($r['cost'] ?? 0)]],
                'MSRP'      => $r['msrp'] ?? null,
                'MAP'       => $r['map_price'] ?? null,
            ]);
        }
        return $out;
    }

    /** JBI's files carry no images. */
    public function images(array $skus): array
    {
        return [];
    }
}
