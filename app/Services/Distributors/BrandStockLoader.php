<?php

namespace App\Services\Distributors;

use App\Models\PlatformDistributorConnection;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Support\BrandDirect;
use RuntimeException;

/**
 * MARKER-BRAND-STOCK: loads a brand-direct supplier's stock file, one per
 * brand, from an upload or the brand's FTP folder. It is checked against the
 * catalog and stored; every shop switched on for the brand then gets its cost
 * and stock from it through the shop's usual sync.
 */
class BrandStockLoader
{
    /** @return array{rows:int,check:array,shops:int} */
    public static function load(string $code, string $path, string $name, string $source, ?int $modified = null): array
    {
        $code = strtoupper($code);
        $read = BrandStockFile::read($path, $name, $code);
        if ($read['missing']) {
            $names = BrandStockFile::fieldLabels();
            throw new RuntimeException('The file is missing the column' . (count($read['missing']) > 1 ? 's' : '') . ' ' . implode(', ', array_map(fn ($k) => $names[$k] ?? $k, $read['missing'])) . '. Is this the ' . BrandStockFile::exportName($code) . '?');
        }
        if (! $read['rows']) {
            throw new RuntimeException('The file has no rows with an Inventory ID.');
        }
        $check = BrandStockFile::check($read['rows'], $code);
        BrandStockStore::replace(BrandStockStore::BRAND, $code, $read['rows'], $name, $source, $check, $modified);
        return ['rows' => count($read['rows']), 'check' => $check, 'shops' => self::syncShops($code)];
    }

    /** Every switched-on shop's items take the new cost and stock. Returns how many shops. */
    public static function syncShops(string $code): int
    {
        $code = strtoupper($code);
        $n = 0;
        foreach (array_keys(BrandDirect::shopsFor($code)) as $tenantId) {
            $active = TenantDistributorCatalogSubscription::where('tenant_id', $tenantId)->where('distributor_code', $code)->where('is_active', true)->exists();
            if (! $active) {
                continue;
            }
            $runId = (string) \Illuminate\Support\Str::uuid();
            \App\Support\DistributorSyncProgress::queued((string) $tenantId, $runId, $code);
            \App\Jobs\RunTenantDistributorSyncJob::dispatch((string) $tenantId, false, 'stock_file', $runId, $code);
            $n++;
        }
        return $n;
    }

    /** The brand's FTP folder, or null when not filled in. */
    public static function ftp(string $code): ?array
    {
        $f = (array) (PlatformDistributorConnection::where('distributor_code', strtoupper($code))->first()?->stock_ftp ?? []);
        return ($f['host'] ?? '') !== '' && ($f['user'] ?? '') !== '' ? $f : null;
    }

    /**
     * Take the newest .xlsx/.csv/.txt from the brand's folder and load it.
     * Returns null when that file is already the one loaded.
     *
     * @return array{rows:int,check:array,shops:int,file:string}|null
     */
    public static function fetch(string $code): ?array
    {
        $code = strtoupper($code);
        $f = self::ftp($code);
        if (! $f) {
            throw new RuntimeException('Add the FTP host, user and password first.');
        }
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('FTP is not available on this server.');
        }
        $conn = @ftp_ssl_connect((string) $f['host'], 21, 20) ?: @ftp_connect((string) $f['host'], 21, 20);
        if (! $conn) {
            throw new RuntimeException('Could not reach ' . $f['host'] . '.');
        }
        $tmp = null;
        try {
            if (! @ftp_login($conn, (string) $f['user'], (string) ($f['password'] ?? ''))) {
                throw new RuntimeException('The FTP login was refused. Check the user and password.');
            }
            ftp_pasv($conn, true);
            $dir = trim((string) ($f['folder'] ?? ''));
            $names = @ftp_nlist($conn, $dir === '' ? '.' : $dir);
            if ($names === false) {
                throw new RuntimeException('Could not list the folder' . ($dir !== '' ? ' ' . $dir : '') . '.');
            }
            $best = null;
            $bestTime = -1;
            foreach ($names as $n) {
                if (! preg_match('/\.(xlsx|csv|txt)$/i', $n)) {
                    continue;
                }
                $t = max(0, (int) @ftp_mdtm($conn, $n));
                if ($t >= $bestTime) {
                    $best = $n;
                    $bestTime = $t;
                }
            }
            if ($best === null) {
                throw new RuntimeException('No .xlsx, .csv or .txt file in the folder.');
            }
            $file = BrandStockStore::file(BrandStockStore::BRAND, $code);
            $base = basename($best);
            if ($file && $file->file_name === $base && $bestTime > 0 && $file->file_modified_at && strtotime((string) $file->file_modified_at) >= $bestTime) {
                BrandStockStore::touchAttempt(BrandStockStore::BRAND, $code);
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'brandstock') . '.' . strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! @ftp_get($conn, $tmp, $best, FTP_BINARY)) {
                throw new RuntimeException('Could not download ' . $base . '.');
            }
            return self::load($code, $tmp, $base, 'ftp', $bestTime > 0 ? $bestTime : null) + ['file' => $base];
        } finally {
            @ftp_close($conn);
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
