<?php

namespace App\Services\Distributors;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\Process\Process;

/**
 * Shimano (and the brands it distributes: PRO, Lazer, Motorex) adapter.
 *
 *   Transport : SFTP through the server's OpenSSH client, one account for the whole country (the username and
 *               password are shared, so they live on the platform connection
 *               in master admin, never per shop).
 *   Feed      : one .xlsx per product family in /US (Hard Goods, Soft Goods,
 *               PRO, Lazer, Motorex). Every file shares the same first 45
 *               columns; each adds its own spec columns after them.
 *
 * There is no API, so this adapter's job is fetch, flatten, and stream:
 *
 *   1. list /US over SFTP and download every .xlsx,
 *   2. read each with a streaming XLSX reader and flatten every row to a small
 *      normalised record (empty spec columns are dropped, so a 250-column row
 *      costs about as much as a 40-column one),
 *   3. keep the records as JSON lines, one per SKU. Every later call
 *      (brands, products, inventory, prices, images) streams that file, so a
 *      shop's stock refresh never re-parses a spreadsheet.
 *
 * The sync layer is written around HLC's nested shape (a product with
 * 'Brand' and 'Variants'), so products() hands back exactly that.
 *
 * Credentials arrive in the api_key slot as "username:password", the same
 * packing BTI uses, so the registry's (apiKey, region) shape still holds.
 *
 * Identifiers, because duplicates are the thing this must not create:
 *   - SKU Code  -> the Shimano item number, kept as the MPN (brand-qualified
 *                  by the identifier service, so it can match HLC/QBP/BTI);
 *   - SKU UPC   -> split into UPC (12 digits) or EAN (13 digits). The feed
 *                  mixes both in one column; Motorex ships EAN-13.
 */
class ShimanoClient implements DistributorAdapter
{
    private string $host;
    private int $port;
    private string $user = '';
    private string $pass = '';
    private string $dir;
    private ?string $hostKeySha256;
    private ?string $hostKeyMd5;
    private ?string $hostKey;
    private bool $insecure;
    private int $timeout;
    private int $cacheMinutes;
    private int $backoffMinutes;
    private string $cacheDir;
    private string $sftpBin;
    private string $keyscanBin;
    private ?string $knownHosts = null;

    /** the feed was rebuilt by THIS adapter instance already */
    private bool $refreshedThisRun = false;

    /** Filenames never imported: Excel lock files and the like. */
    private const SKIP = '/^(~\$|\.)/';

    /** First-column words that are an internal family prefix, not part of the category. */
    private const CATEGORY_PREFIXES = ['hg', 'lsg', 'pro', 'lazer', 'motorex', 'shimano'];

    /** Country codes seen in the feed. Unknown codes are shown as given. */
    private const COUNTRIES = [
        'CN' => 'China', 'TW' => 'Taiwan', 'MY' => 'Malaysia', 'VN' => 'Vietnam', 'JP' => 'Japan',
        'ID' => 'Indonesia', 'TH' => 'Thailand', 'KH' => 'Cambodia', 'IN' => 'India', 'PH' => 'Philippines',
        'KR' => 'South Korea', 'SG' => 'Singapore', 'US' => 'United States', 'MX' => 'Mexico',
        'DE' => 'Germany', 'IT' => 'Italy', 'FR' => 'France', 'CH' => 'Switzerland', 'PT' => 'Portugal',
        'ES' => 'Spain', 'GB' => 'United Kingdom', 'CZ' => 'Czechia', 'SK' => 'Slovakia', 'HU' => 'Hungary',
    ];

    /** Columns that are identity, media or bookkeeping, never a spec the shop reads. */
    private const NOT_A_SPEC = [
        'sku code', 'sku name', 'sku upc', 'sku video url', 'sku map', 'sku map break eligible',
        'sku manual media path', 'sku color', 'sku series', 'sku generation',
        'product type', 'product b2b sport', 'product collection', 'product gender',
        'product manual media path', 'product exploded image', 'small parts', 'product has mips',
        'product image spec table', 'product video description', 'product video lifestyle',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region = 'us',
        ?array $config = null,
    ) {
        $cfg = $config ?? config('distributors.shimano', []);

        $this->host          = (string) ($cfg['host'] ?? 'ftpsna.shimano.com');
        $this->port          = (int) ($cfg['port'] ?? 22);
        $this->hostKeySha256 = ($cfg['host_key_sha256'] ?? null) ?: null;
        $this->hostKeyMd5    = ($cfg['host_key_md5'] ?? null) ?: null;
        $this->hostKey       = trim((string) ($cfg['host_key'] ?? '')) ?: null;
        $this->insecure      = (bool) ($cfg['insecure'] ?? false);
        $this->timeout       = (int) ($cfg['timeout'] ?? 300);
        $this->cacheMinutes  = (int) ($cfg['cache_minutes'] ?? 30);
        $this->backoffMinutes = (int) ($cfg['backoff_minutes'] ?? 30);
        $this->sftpBin       = (string) ($cfg['sftp'] ?? 'sftp');
        $this->keyscanBin    = (string) ($cfg['keyscan'] ?? 'ssh-keyscan');
        $this->cacheDir      = (string) ($cfg['cache_dir'] ?? storage_path('app/distributor-cache'));

        // the folder is the region in capitals: US today, CA when that account is added.
        $this->dir = strtoupper(trim((string) ($cfg['dir'] ?? $region ?: 'us')));

        if (str_contains($apiKey, ':')) {
            [$this->user, $this->pass] = explode(':', $apiKey, 2);
        } else {
            $this->user = $apiKey;
        }
    }

    public function code(): string { return 'SHIMANO'; }
    public function name(): string { return 'Shimano'; }

    /** Tells the sync to ask for one brand at a time, so a brand switched off is never read. */
    public function pagesByBrand(): bool { return true; }

    // ------------------------------------------------------------- probe

    public function testConnection(): array
    {
        try {
            $names = $this->listRemote();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            return [
                'ok'     => false,
                'status' => null,
                'auth'   => str_contains($msg, 'Permission denied') || str_contains($msg, 'Authentication'),
                'body'   => $msg,
            ];
        }

        return [
            'ok'     => $names !== [],
            'status' => 200,
            'auth'   => false,
            'body'   => $names !== []
                ? count($names) . ' feed file' . (count($names) === 1 ? '' : 's') . ' in /' . $this->dir . ': ' . implode(', ', $names)
                : 'connected, but /' . $this->dir . ' has no .xlsx files',
        ];
    }

    // --------------------------------------------------------- transport

    /** Does this key blob carry the pinned fingerprint? */
    private function matchesPin(string $blob): bool
    {
        $wantSha = $this->hostKeySha256 ? rtrim(trim($this->hostKeySha256), '=') : null;
        $wantMd5 = $this->hostKeyMd5 ? strtolower(str_replace(':', '', $this->hostKeyMd5)) : null;

        return ($wantSha !== null && hash_equals($wantSha, rtrim(base64_encode(hash('sha256', $blob, true)), '=')))
            || ($wantMd5 !== null && hash_equals($wantMd5, md5($blob)));
    }

    /**
     * A private known_hosts file holding Shimano's key and nothing else.
     * OpenSSH then refuses any server that does not present exactly that key.
     *
     * The key normally ships in config (Shimano's server refuses ssh-keyscan).
     * It is checked against the fingerprint pin before use, so a wrong key in
     * config fails loudly instead of trusting the wrong server.
     */
    private function knownHostsFile(): string
    {
        if ($this->knownHosts !== null) {
            return $this->knownHosts;
        }
        if (! $this->hostKey && ! $this->hostKeySha256 && ! $this->hostKeyMd5) {
            if ($this->insecure) {
                return $this->knownHosts = '/dev/null';
            }
            throw new \RuntimeException('Shimano: no host key is configured (SHIMANO_HOST_KEY)');
        }

        if ($this->hostKey) {
            $f = preg_split('/\s+/', $this->hostKey);
            $blob = isset($f[1]) ? base64_decode($f[1], true) : false;
            if ($blob === false || ! ($this->hostKeySha256 || $this->hostKeyMd5) || ! $this->matchesPin($blob)) {
                throw new \RuntimeException('Shimano: the configured host key does not match the pinned fingerprint');
            }
            return $this->knownHosts = $this->writeKnownHosts($f[0], $f[1]);
        }

        // fallback: no key shipped, so ask the server for it and check the pin
        $lastErr = '';
        $sawKey  = false;
        for ($try = 1; $try <= 4; $try++) {
            $p = new Process([$this->keyscanBin, '-T', '30', '-p', (string) $this->port, $this->host]);
            $p->setTimeout(60);
            $p->run();
            $lastErr = trim($p->getErrorOutput());

            foreach (preg_split('/\R/', $p->getOutput()) as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) < 3 || $f[0] === '' || $f[0][0] === '#') { continue; }
                $blob = base64_decode($f[2], true);
                if ($blob === false) { continue; }
                $sawKey = true;
                if ($this->matchesPin($blob)) {
                    return $this->knownHosts = $this->writeKnownHosts($f[1], $f[2]);
                }
            }
            if ($try < 4) { sleep(2); }
        }

        throw new \RuntimeException($sawKey
            ? 'Shimano: the server presented a key that does not match the pinned fingerprint (host key mismatch)'
            : 'Shimano: the server did not return its host key' . ($lastErr !== '' ? ' (' . $lastErr . ')' : ''));
    }

    /** Written to a temp file and renamed, so a leftover file owned by another user never blocks it. */
    private function writeKnownHosts(string $type, string $b64): string
    {
        $file = $this->workDir() . '/shimano-known_hosts';
        $host = $this->port === 22 ? $this->host : '[' . $this->host . ']:' . $this->port;
        $tmp  = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $host . ' ' . $type . ' ' . $b64 . "\n");
        chmod($tmp, 0600);
        rename($tmp, $file);
        return $file;
    }

    private function workDir(): string
    {
        $d = rtrim($this->cacheDir, '/');
        if (! is_dir($d)) { @mkdir($d, 0775, true); }
        return $d;
    }

    /** A tiny script ssh runs to ask for the password; the secret itself travels in the environment. */
    private function askpass(): string
    {
        $f = $this->workDir() . '/shimano-askpass.sh';
        $body = "#!/bin/sh\nprintf '%s\\n' \"\$SHIMANO_SFTP_PW\"\n";
        if (! is_file($f) || ! is_readable($f) || file_get_contents($f) !== $body || ! is_executable($f)) {
            $tmp = $f . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, $body);
            chmod($tmp, 0700);
            rename($tmp, $f);
        }
        return $f;
    }

    /**
     * Runs sftp with the given commands on stdin. The password is never in
     * the command line: ssh fetches it from the askpass script, which reads
     * it from this process's environment only.
     */
    private function backoffFile(): string
    {
        return $this->workDir() . '/shimano-backoff.json';
    }

    /**
     * After a failed connection nothing contacts Shimano again for a while.
     * Their server blocks an address that keeps retrying, and every shop's
     * stock refresh would otherwise retry on its own and keep the block alive.
     */
    private function guardBackoff(): void
    {
        $f = $this->backoffFile();
        if (! is_file($f)) {
            return;
        }
        $b = json_decode((string) @file_get_contents($f), true) ?: [];
        $until = (int) ($b['until'] ?? 0);
        if ($until > time()) {
            $mins = (int) ceil(($until - time()) / 60);
            throw new \RuntimeException('Shimano: paused after a failed connection, so their server '
                . 'does not block us for retrying. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's')
                . '. Last error: ' . ($b['error'] ?? 'unknown'));
        }
    }

    private function setBackoff(string $error): void
    {
        $f   = $this->backoffFile();
        $tmp = $f . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode([
            'at' => time(), 'until' => time() + $this->backoffMinutes * 60, 'error' => mb_substr($error, 0, 300),
        ]));
        rename($tmp, $f);
    }

    private function sftp(string $commands): string
    {
        $this->guardBackoff();

        $cmd = [
            $this->sftpBin,
            '-P', (string) $this->port,
            '-o', 'StrictHostKeyChecking=' . ($this->insecure && ! $this->hostKeySha256 && ! $this->hostKeyMd5 ? 'no' : 'yes'),
            '-o', 'UserKnownHostsFile=' . $this->knownHostsFile(),
            '-o', 'PreferredAuthentications=password',
            '-o', 'PubkeyAuthentication=no',
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'ConnectTimeout=20',
            $this->user . '@' . $this->host,
        ];

        $p = new Process($cmd, null, [
            'SSH_ASKPASS'          => $this->askpass(),
            'SSH_ASKPASS_REQUIRE'  => 'force',
            'DISPLAY'              => 'intake',
            'SHIMANO_SFTP_PW'      => $this->pass,
        ]);
        $p->setTimeout($this->timeout);
        $p->setInput($commands . "\nbye\n");
        $p->run();

        if (! $p->isSuccessful()) {
            $err = trim($p->getErrorOutput());
            $msg = 'Shimano SFTP failed (exit ' . $p->getExitCode() . ')' . ($err !== '' ? ': ' . $err : '');
            $this->setBackoff($msg);
            throw new \RuntimeException($msg);
        }
        @unlink($this->backoffFile());
        return $p->getOutput() . "\n" . $p->getErrorOutput();
    }

    private function q(string $v): string
    {
        return '"' . addcslashes($v, '"\\') . '"';
    }

    /** @return array<int,string> .xlsx file names in the region folder */
    private function listRemote(): array
    {
        $out = $this->sftp('ls -1 ' . $this->q($this->dir));

        $names = [];
        foreach (preg_split('/\R/', $out) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'sftp>') || ! preg_match('/\.xlsx$/i', $line)) {
                continue;
            }
            $name = basename($line);
            if (preg_match(self::SKIP, $name)) { continue; }
            $names[$name] = $name;
        }
        $names = array_values($names);
        sort($names);
        return $names;
    }

    // ------------------------------------------------------------ cache

    private function cachePath(): string
    {
        return rtrim($this->cacheDir, '/') . '/shimano-' . strtolower($this->dir) . '.jsonl';
    }

    /**
     * The flattened feed, rebuilt when it is missing, older than the cache
     * window, or when $force says the caller needs today's file (a catalog
     * sync does; a shop's stock refresh does not).
     */
    private function feed(bool $force = false): string
    {
        $path = $this->cachePath();

        $fresh = is_file($path) && filesize($path) > 0
            && (time() - filemtime($path)) < $this->cacheMinutes * 60;

        if (($force && ! $this->refreshedThisRun) || ! $fresh) {
            try {
                $this->rebuild($path);
            } catch (\Throwable $e) {
                // a catalog sync must say it failed; a shop's stock refresh
                // reads the last good copy rather than failing or retrying
                if ($force || ! is_file($path) || filesize($path) === 0) {
                    throw $e;
                }
                return $path;
            }
            $this->refreshedThisRun = true;
        }
        return $path;
    }

    private function rebuild(string $path): void
    {
        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $work = rtrim($this->cacheDir, '/') . '/shimano-' . strtolower($this->dir) . '-files';
        if (! is_dir($work)) {
            @mkdir($work, 0775, true);
        }
        foreach (glob($work . '/*') ?: [] as $old) {
            @unlink($old);   // never parse a file left by an earlier, broken run
        }

        // one connection fetches every file: Shimano blocks bursts of logins.
        // The glob stays outside quotes (sftp does not expand it inside them).
        $this->sftp('get ' . str_replace(' ', '\\ ', $this->dir) . '/*.xlsx ' . $this->q($work . '/'));

        $names = [];
        foreach (glob($work . '/*') ?: [] as $f) {
            $n = basename($f);
            if (! preg_match('/\.xlsx$/i', $n) || preg_match(self::SKIP, $n)) { continue; }
            if (filesize($f) < 1024) {
                throw new \RuntimeException("Shimano feed file {$n} came back empty");
            }
            $names[] = $n;
        }
        sort($names);
        if ($names === []) {
            throw new \RuntimeException('Shimano: no .xlsx files found in /' . $this->dir);
        }

        $tmp = $path . '.part';
        $out = fopen($tmp, 'w');
        if ($out === false) {
            throw new \RuntimeException('Shimano: cannot write ' . $tmp);
        }

        $count = 0;
        try {
            foreach ($names as $name) {
                $local = $work . '/' . $name;
                foreach ($this->readWorkbook($local, $name) as $row) {
                    fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $count++;
                }
                @unlink($local);   // flattened; the spreadsheet is not needed again
            }
        } finally {
            fclose($out);
        }

        if ($count === 0) {
            @unlink($tmp);
            throw new \RuntimeException('Shimano: the feed files held no SKU rows');
        }
        rename($tmp, $path);
    }

    /**
     * Streams the first sheet of one workbook as normalised records.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    private function readWorkbook(string $file, string $label): \Generator
    {
        $options = new Options();
        $reader  = new Reader($options);
        $reader->open($file);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if ($header === null) {
                        // the headers carry stray spaces ("Product Long Description ")
                        $header = array_map(fn ($h) => trim((string) $h), $cells);
                        continue;
                    }
                    $rec = [];
                    foreach ($header as $i => $h) {
                        if ($h === '') { continue; }
                        $v = $cells[$i] ?? null;
                        if ($v === null) { continue; }
                        $rec[$h] = $v;
                    }
                    $n = $this->normalize($rec, $label);
                    if ($n !== null) {
                        yield $n;
                    }
                }
                break;   // the data is on the first sheet
            }
        } finally {
            $reader->close();
        }
    }

    // -------------------------------------------------------- normalise

    private function s(array $r, string $col): string
    {
        $v = $r[$col] ?? '';
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) || is_int($v)) {
            $v = (floor((float) $v) == (float) $v) ? sprintf('%.0f', $v) : (string) $v;
        }
        // Color Name and Color Image arrive with a leading newline
        return trim((string) $v, " \t\n\r\0\x0B\xC2\xA0");
    }

    private function num(array $r, string $col): ?float
    {
        $v = $r[$col] ?? null;
        if ($v === null || $v === '') { return null; }
        $v = str_replace([',', '$'], '', (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /** @return array{0:string,1:string} [upc, ean] */
    private function barcode(mixed $raw): array
    {
        if (is_float($raw) || is_int($raw)) {
            $raw = sprintf('%.0f', $raw);
        }
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '' || trim($d, '0') === '') {
            return ['', ''];
        }
        $n = strlen($d);
        if ($n === 11) {                       // a UPC-A that lost its leading zero in a spreadsheet
            $d = '0' . $d;
            $n = 12;
        }
        if ($n === 12) {
            return [$d, ''];
        }
        if ($n === 13) {
            // 0 + a UPC-A is the same product's EAN-13 form
            return str_starts_with($d, '0') ? [substr($d, 1), ''] : ['', $d];
        }
        if ($n === 14 && str_starts_with($d, '0')) {
            return $this->barcode(substr($d, 1));
        }
        return in_array($n, [8, 14], true) ? ['', $d] : ['', ''];
    }

    /** "100+" means at least 100, and is stored as 100. */
    private function qty(mixed $raw): array
    {
        $v = trim((string) $raw);
        if ($v === '') { return [0, false]; }
        $plus = str_ends_with($v, '+');
        $n = (int) preg_replace('/[^0-9]/', '', $v);
        return [max(0, $n), $plus];
    }

    private function date(mixed $raw): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        $v = trim((string) $raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
    }

    /** "Chromoly Steel" -> "Chromoly steel". Codes and single words are left alone. */
    private function tidy(string $v): string
    {
        $v = trim($v);
        if (strtoupper($v) === 'X') {
            return 'Yes';
        }
        if (preg_match('/^\p{Lu}\p{Ll}+( \p{Lu}\p{Ll}+)+$/u', $v)) {
            return mb_substr($v, 0, 1) . mb_strtolower(mb_substr($v, 1));
        }
        return $v;
    }

    /** "HG_Pedals" -> "Pedals". "LSG_Footwear_insoles_and_parts" -> "Footwear insoles and parts". */
    private function categoryName(string $type): string
    {
        $parts = explode('_', trim($type));
        if (count($parts) > 1 && in_array(strtolower($parts[0]), self::CATEGORY_PREFIXES, true)) {
            array_shift($parts);
        }
        $name = trim(implode(' ', $parts));
        return $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    private function clean(string $html): string
    {
        $t = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    }

    /** @return array<string,mixed>|null null for a row with no SKU */
    private function normalize(array $r, string $file): ?array
    {
        $sku = $this->s($r, 'SKU Code');
        if ($sku === '') {
            return null;
        }

        [$upc, $ean] = $this->barcode($r['SKU UPC'] ?? '');

        // color "ONE" is a placeholder for "no color choice", not a color
        $color = $this->s($r, 'Color Name');
        if (in_array(strtoupper($color), ['ONE', 'NONE', 'N/A'], true)) {
            $color = '';
        }

        // images, in the order a shop wants to see them
        $images = [];
        foreach (['Primary', 'Back', 'Detail', 'Lifestyle', 'Medial', 'On Body', 'Sole', 'Top'] as $slot) {
            $u = $this->s($r, 'SKU Image ' . $slot);
            if ($u !== '' && ! in_array($u, $images, true)) {
                $images[] = $u;
            }
        }

        // Text. Soft Goods keeps its marketing copy in the "Supplemental" columns.
        $short    = $this->clean($this->s($r, 'Product Short Description') ?: $this->s($r, 'Supplemental Product Short Description'));
        $headline = $this->clean($this->s($r, 'Product Long Description Headline') ?: $this->s($r, 'Supplemental Product Headline'));
        $long     = $this->clean($this->s($r, 'Product Long Description') ?: $this->s($r, 'Supplemental Product Long Description'));
        $cont     = $this->clean($this->s($r, 'Product Long Description Continued'));

        $paras = [];
        foreach ([$headline, $long, $short, $cont] as $p) {
            if ($p === '') { continue; }
            foreach ($paras as $q) {
                if (stripos($q, $p) !== false) { continue 2; }   // already said, in a longer paragraph
            }
            $paras[] = $p;
        }

        $features = [];
        $featRaw = $this->s($r, 'Product Features/Benefits') ?: $this->s($r, 'Supplemental Product Features');
        foreach (explode('|', $featRaw) as $f) {
            $f = trim(ltrim(trim($f), "•*- \t"));
            if ($f !== '') { $features[] = $this->clean($f); }
        }

        $description = implode("\n\n", $paras);
        if ($features) {
            $description .= ($description !== '' ? "\n\n" : '') . implode("\n", $features);
        }

        // Specs: every populated SKU / spec column becomes a Name => Value pair.
        $attrs = [];
        foreach ($r as $h => $v) {
            $lh = strtolower($h);
            if (in_array($lh, self::NOT_A_SPEC, true)) { continue; }
            if (! preg_match('/^(sku|product spec) /i', $h)) { continue; }
            if (str_starts_with($lh, 'sku image') || str_starts_with($lh, 'product image') || str_starts_with($lh, 'product video')) { continue; }
            $val = $this->s($r, $h);
            if ($val === '') { continue; }
            $label = trim(preg_replace('/^(sku|product spec) /i', '', $h));
            $attrs[] = ['Name' => $label, 'Value' => $this->tidy(str_replace('|', ', ', $val))];
        }
        if (($gender = $this->s($r, 'Product Gender')) !== '' && strtolower($gender) !== 'unisex') {
            $attrs[] = ['Name' => 'Gender', 'Value' => $this->tidy($gender)];
        }
        if (($sport = $this->s($r, 'Product B2B Sport')) !== '' && strtolower($sport) !== strtolower($this->s($r, 'Brand'))) {
            $attrs[] = ['Name' => 'Riding', 'Value' => str_replace('|', ', ', $sport)];
        }
        if (($cc = strtoupper($this->s($r, 'Country Of Origin'))) !== '') {
            $attrs[] = ['Name' => 'Made in', 'Value' => self::COUNTRIES[$cc] ?? $cc];
        }

        [$irv, $irvPlus] = $this->qty($r['Inventory US IRV'] ?? '');
        [$nch, $nchPlus] = $this->qty($r['Inventory US NCH'] ?? '');

        $msrp  = $this->num($r, 'Price USD MSRP');
        $isMap = strtolower($this->s($r, 'SKU Map')) === 'yes';

        $dims = array_values(array_filter([
            $this->num($r, 'Length'), $this->num($r, 'Width'), $this->num($r, 'Height'),
        ], fn ($d) => $d !== null && $d > 0));

        $video = $this->s($r, 'SKU Video URL') ?: $this->s($r, 'Product Video URL');

        $out = [
            'brand'        => $this->s($r, 'Brand'),
            'product_code' => $this->s($r, 'Product Code'),
            'sku'          => $sku,
            'name'         => $this->s($r, 'SKU Name'),
            'product_name' => $this->s($r, 'Product Name'),
            'upc'          => $upc,
            'ean'          => $ean,
            'color'        => $color,
            'size'         => in_array(strtoupper($this->s($r, 'Size')), ['ONE', 'OS', 'N/A'], true) ? '' : $this->tidy($this->s($r, 'Size')),
            'description'  => $description,
            'features'     => $features,
            'ptype'        => $this->s($r, 'Product Type'),
            'category'     => $this->categoryName($this->s($r, 'Product Type')),
            'images'       => $images,
            'video'        => $video,
            'irv'          => $irv,
            'irv_plus'     => $irvPlus,
            'nch'          => $nch,
            'nch_plus'     => $nchPlus,
            'irv_next'     => $this->date($r['Inventory next available date US IRV'] ?? ''),
            'nch_next'     => $this->date($r['Inventory next available date US NCH'] ?? ''),
            'cost'         => $this->num($r, 'Price USD MSWP'),
            'msrp'         => $msrp,
            // MAP: this file says only whether an item has one, and the price is the MSRP
            'map'          => $isMap,
            'map_price'    => $isMap ? $msrp : null,
            'map_break'    => strtolower($this->s($r, 'SKU Map Break Eligible')) === 'yes',
            'weight'       => $this->num($r, 'Weight'),
            'dimensions'   => $dims,
            'origin'       => $this->s($r, 'Country Of Origin'),
            'attrs'        => $attrs,
            'file'         => $file,
        ];

        // drop empties so the cache stays small
        return array_filter($out, fn ($v) => $v !== '' && $v !== null && $v !== []);
    }

    // ---------------------------------------------------------- reading

    /** @return \Generator<int,array<string,mixed>> */
    private function rows(bool $force = false): \Generator
    {
        $fh = fopen($this->feed($force), 'r');
        if ($fh === false) {
            return;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    // -------------------------------------------------------------- api

    /**
     * Brands come from the Brand column. This is the call a catalog sync makes
     * first, so it is also where the feed is refreshed from Shimano: a shop's
     * stock refresh never reaches here and reuses the cached copy.
     */
    public function brands(): array
    {
        $out = [];
        foreach ($this->rows(true) as $r) {
            $b = (string) ($r['brand'] ?? '');
            if ($b !== '' && ! isset($out[$b])) {
                $out[$b] = ['id' => $b, 'name' => $b];
            }
        }
        ksort($out);
        return array_values($out);
    }

    public function categories(): array
    {
        $out = [];
        foreach ($this->rows() as $r) {
            $t = (string) ($r['ptype'] ?? '');
            if ($t === '' || isset($out[$t])) { continue; }
            $n = (string) ($r['category'] ?? $t);
            $out[$t] = ['id' => $t, 'name' => $n, 'parent_id' => '', 'path' => $n];
        }
        return array_values($out);
    }

    /**
     * @param array{brands?: array, upcs?: array, vendorParts?: array, pageStartIndex?: int, pageSize?: int} $opts
     */
    public function products(array $opts = []): array
    {
        $brands = array_flip(array_map(fn ($b) => mb_strtolower(trim((string) $b)), $opts['brands'] ?? []));
        $upcs   = array_flip(array_map('strval', $opts['upcs'] ?? []));
        $parts  = array_flip(array_map(fn ($p) => strtoupper(trim((string) $p)), $opts['vendorParts'] ?? []));
        $filtered = $upcs || $parts;

        $start = max(0, ((int) ($opts['pageStartIndex'] ?? 1)) - 1);
        $size  = (int) ($opts['pageSize'] ?? 0);

        $byProduct = [];
        $pos = [];
        $next = 0;

        foreach ($this->rows() as $r) {
            if ($brands && ! isset($brands[mb_strtolower((string) ($r['brand'] ?? ''))])) {
                continue;
            }
            if ($filtered) {
                $hitUpc  = $upcs && (isset($upcs[$r['upc'] ?? '']) || isset($upcs[$r['ean'] ?? '']));
                $hitPart = $parts && isset($parts[strtoupper((string) ($r['sku'] ?? ''))]);
                if (! $hitUpc && ! $hitPart) { continue; }
            }

            $gid = ($r['brand'] ?? '') . '|' . ($r['product_code'] ?? $r['sku']);
            if (! array_key_exists($gid, $pos)) {
                $pos[$gid] = $next++;
            }
            if ($size > 0 && ($pos[$gid] < $start || $pos[$gid] >= $start + $size)) {
                continue;
            }

            if (! isset($byProduct[$gid])) {
                $byProduct[$gid] = [
                    // what the sync groups on
                    'Brand'        => ($r['brand'] ?? '') !== '' ? $r['brand'] : 'Unknown',
                    'product_code' => $r['product_code'] ?? $r['sku'],
                    'Name'         => $r['product_name'] ?? ($r['name'] ?? ''),
                    'Variants'     => [],
                ];
            }
            $r['VariantNo'] = $r['sku'];
            $byProduct[$gid]['Variants'][] = $r;
        }

        return ['Products' => array_values($byProduct), 'Failures' => []];
    }

    /**
     * Stock, one entry per warehouse. IRV and NCH are kept apart because they
     * are different shelves with different restock dates; the shop sees both.
     */
    public function inventory(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];

        foreach ($this->rows() as $r) {
            $sku = (string) ($r['sku'] ?? '');
            if ($want && ! isset($want[$sku])) { continue; }

            $irv = (int) ($r['irv'] ?? 0);
            $nch = (int) ($r['nch'] ?? 0);

            $out[] = [
                'VariantNo'         => $sku,
                'TotalQtyAvailable' => $irv + $nch,
                'Warehouses' => [
                    ['Code' => 'IRV', 'Name' => 'IRV', 'QtyAvailable' => $irv,
                     'AtLeast' => (bool) ($r['irv_plus'] ?? false), 'NextDueDate' => $r['irv_next'] ?? null],
                    ['Code' => 'NCH', 'Name' => 'NCH', 'QtyAvailable' => $nch,
                     'AtLeast' => (bool) ($r['nch_plus'] ?? false), 'NextDueDate' => $r['nch_next'] ?? null],
                ],
            ];
        }
        return $out;
    }

    /**
     * Dealer cost, MSRP and MAP. The raw record is kept underneath because
     * the field map reads it by key (cost_cents <- cost), exactly as it does
     * for BTI.
     */
    public function prices(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];

        foreach ($this->rows() as $r) {
            $sku = (string) ($r['sku'] ?? '');
            if ($want && ! isset($want[$sku])) { continue; }

            $out[] = array_merge($r, [
                'VariantNo' => $sku,
                'Prices'    => [['PriceTypeId' => 1, 'PriceType' => 'Base', 'Price' => (float) ($r['cost'] ?? 0)]],
                'MSRP'      => $r['msrp'] ?? null,
                'MAP'       => $r['map_price'] ?? null,
            ]);
        }
        return $out;
    }

    public function images(array $skus): array
    {
        $want = array_flip(array_map('strval', $skus));
        $out = [];

        foreach ($this->rows() as $r) {
            $sku = (string) ($r['sku'] ?? '');
            if (($want && ! isset($want[$sku])) || empty($r['images'])) { continue; }

            $out[] = ['VariantNo' => $sku, 'sku' => $sku, 'images' => $r['images']];
        }
        return $out;
    }
}
