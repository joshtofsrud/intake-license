<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Read-only look at the J&B (JBI) FTP feed before the adapter is built.
 * Logs in, lists inv/v2, and for each of the four v2 files checks the header
 * against JBI's published column list, shows three sample rows, and counts
 * rows. Files are pulled to a temp file and deleted; nothing is saved and
 * the login is asked for at the prompt, never stored.
 *
 *   php artisan jbi:ftp-check
 *   php artisan jbi:ftp-check --rows=5
 */
class JbiFtpCheck extends Command
{
    protected $signature = 'jbi:ftp-check {--rows=3 : sample rows to show per file} {--host=ftp.jbi.bike}';
    protected $description = 'Read-only check of the JBI FTP files against their docs';

    /** Column lists from api.jbi.bike/docs (v2, document version 1.0, 2026-05-23). */
    private function expected(): array
    {
        $bullets = ['item_id', 'upc_ean', 'cat_1', 'cat_2', 'cat_3', 'cat_4'];
        for ($i = 1; $i <= 20; $i++) { $bullets[] = "model_bullet_{$i}"; }
        for ($i = 1; $i <= 20; $i++) { $bullets[] = "product_bullet_{$i}"; }

        $spec = ['part_number', 'upc_ean'];
        for ($i = 1; $i <= 20; $i++) { $spec[] = "title_{$i}"; $spec[] = "spec_{$i}"; }

        $loc = ['item_id', 'upc_ean', 'avail_total'];
        foreach ([101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112] as $w) { $loc[] = "avail_{$w}"; }

        return [
            'inv_mast.txt' => ['item_id', 'description', 'brand', 'model', 'upc_ean', 'supplier_part_no',
                'case_qty', 'uom', 'price', 'msrp', 'map', 'weight', 'length', 'width', 'height', 'cube',
                'ormd', 'warning_label', 'date_created', 'restricted_online', 'dropshipable', 'nfa', 'nfas',
                'qty_restriction', 'qty_restriction_period'],
            'inv_loc.txt'     => $loc,
            'inv_bullets.txt' => $bullets,
            'inv_spec.txt'    => $spec,
        ];
    }

    public function handle(): int
    {
        if (! function_exists('ftp_connect')) {
            $this->error('PHP on this server has no FTP extension (ftp_connect is missing).');
            return self::FAILURE;
        }

        $user = (string) $this->ask('JBI FTP username');
        $pass = (string) $this->secret('JBI FTP password (not shown, not saved)');
        if ($user === '' || $pass === '') {
            $this->error('Username and password are both needed.');
            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        $ftp  = @ftp_connect($host, 21, 30);
        if (! $ftp) {
            $this->error("Could not reach {$host} on port 21.");
            return self::FAILURE;
        }
        if (! @ftp_login($ftp, $user, $pass)) {
            ftp_close($ftp);
            $this->error('Login refused. Check the username and password JBI sent.');
            return self::FAILURE;
        }
        ftp_pasv($ftp, true);
        $this->info("Logged in to {$host}. Landed in: " . (ftp_pwd($ftp) ?: '?'));

        $dir = $this->findDir($ftp);
        if ($dir === null) {
            $this->error('No inv/v2 folder found. Top level holds: ' . implode(', ', $this->names($ftp, '.')));
            ftp_close($ftp);
            return self::FAILURE;
        }
        $this->line("Files in {$dir}: " . implode(', ', $this->names($ftp, $dir)));

        $rows = max(1, (int) $this->option('rows'));
        foreach ($this->expected() as $file => $cols) {
            $this->newLine();
            $this->line(str_repeat('─', 70));
            $this->info($file);
            $this->checkFile($ftp, "{$dir}/{$file}", $cols, $rows);
        }

        ftp_close($ftp);
        $this->newLine();
        $this->info('Done. Nothing was saved.');
        return self::SUCCESS;
    }

    /** Where inv/v2 sits: right where we land, or one folder down. */
    private function findDir($ftp): ?string
    {
        if (@ftp_chdir($ftp, 'inv/v2')) {
            ftp_chdir($ftp, '../..');
            return 'inv/v2';
        }
        foreach ($this->names($ftp, '.') as $name) {
            if (@ftp_chdir($ftp, "{$name}/inv/v2")) {
                ftp_chdir($ftp, '../../..');
                return "{$name}/inv/v2";
            }
        }
        return null;
    }

    private function names($ftp, string $dir): array
    {
        $list = @ftp_nlist($ftp, $dir) ?: [];
        return array_values(array_filter(array_map('basename', $list), fn ($n) => $n !== '.' && $n !== '..'));
    }

    private function checkFile($ftp, string $path, array $expected, int $sampleRows): void
    {
        $size = @ftp_size($ftp, $path);
        if ($size < 0) {
            $this->error('  Not found.');
            return;
        }
        $mdtm = @ftp_mdtm($ftp, $path);
        $this->line('  Size: ' . number_format($size / 1048576, 1) . ' MB'
            . ($mdtm > 0 ? '   Updated: ' . date('Y-m-d H:i T', $mdtm) : ''));

        $tmp = tempnam(sys_get_temp_dir(), 'jbi');
        try {
            $started = microtime(true);
            if (! @ftp_get($ftp, $tmp, $path, FTP_BINARY)) {
                $this->error('  Download failed.');
                return;
            }
            $this->line('  Downloaded in ' . round(microtime(true) - $started, 1) . 's');

            $fh = fopen($tmp, 'rb');
            $first = fgets($fh);
            $crlf  = str_ends_with((string) $first, "\r\n");
            $header = $this->split((string) $first);
            $bom = str_starts_with($header[0] ?? '', "\xEF\xBB\xBF");
            if ($bom) { $header[0] = substr($header[0], 3); }

            $delim = substr_count((string) $first, "\t") ? 'tab' : (substr_count((string) $first, '|') ? 'pipe' : (substr_count((string) $first, ',') ? 'comma' : 'unknown'));
            $this->line("  Delimiter: {$delim}   Line ends: " . ($crlf ? 'CRLF' : 'LF') . ($bom ? '   Starts with a BOM' : ''));

            $missing = array_values(array_diff($expected, $header));
            $extra   = array_values(array_diff($header, $expected));
            if ($header === $expected) {
                $this->info('  Header: matches the docs, ' . count($header) . ' columns in order');
            } else {
                $this->warn('  Header: ' . count($header) . ' columns, docs say ' . count($expected));
                if ($missing) { $this->warn('    Missing: ' . implode(', ', $missing)); }
                if ($extra)   { $this->warn('    Not in docs: ' . implode(', ', $extra)); }
                if (! $missing && ! $extra) { $this->warn('    Same columns, different order'); }
                $this->line('    Actual: ' . implode(', ', $header));
            }

            $n = 0; $badWidth = 0; $backticks = 0; $samples = [];
            $width = count($header);
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '') { continue; }
                $n++;
                $cells = $this->split($line);
                if (count($cells) !== $width) { $badWidth++; }
                if (str_contains($line, '`')) { $backticks++; }
                if (count($samples) < $sampleRows) { $samples[] = $cells; }
            }
            fclose($fh);

            $this->line('  Rows: ' . number_format($n)
                . ($badWidth ? '   ' . number_format($badWidth) . ' with the wrong number of columns' : '   all the right width')
                . '   Rows with a backtick: ' . number_format($backticks));

            foreach ($samples as $i => $cells) {
                $this->line('  Sample ' . ($i + 1) . ':');
                foreach ($header as $c => $name) {
                    $v = $cells[$c] ?? '';
                    if ($v === '') { continue; }   // blank bullet and spec slots are noise
                    $this->line(sprintf('    %-24s %s', $name, mb_strimwidth($v, 0, 90, '…')));
                }
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function split(string $line): array
    {
        return explode("\t", rtrim($line, "\r\n"));
    }
}
