<?php

namespace App\Services\Distributors;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use RuntimeException;

/**
 * MARKER-BRAND-STOCK: loads a shop's stock file for a brand-direct supplier, from an upload or from
 * the shop's FTP folder, checks it against the catalog and stores it. Costs
 * and quantities then reach the shop's items through the normal per-shop sync.
 */
class BrandStockLoader
{
    /** @return array{rows:int,check:array} */
    public static function load(string $tenantId, string $code, string $path, string $name, string $source, ?int $modified = null): array
    {
        $read = BrandStockFile::read($path, $name, $code);
        if ($read['missing']) {
            $names = BrandStockFile::fieldLabels();
            throw new RuntimeException('The file is missing the column' . (count($read['missing']) > 1 ? 's' : '') . ' ' . implode(', ', array_map(fn ($k) => $names[$k] ?? $k, $read['missing'])) . '. Is this the ' . BrandStockFile::exportName($code) . '?');
        }
        if (! $read['rows']) {
            throw new RuntimeException('The file has no rows with an Inventory ID.');
        }
        $check = BrandStockFile::check($read['rows'], $code);
        BrandStockStore::replace($tenantId, $code, $read['rows'], $name, $source, $check, $modified);
        return ['rows' => count($read['rows']), 'check' => $check];
    }

    /** The shop's FTP settings, or null when not filled in. */
    public static function ftp(TenantDistributorCatalogSubscription $sub): ?array
    {
        $f = (array) (((array) ($sub->credentials_encrypted ?? []))['stock_ftp'] ?? []);
        return ($f['host'] ?? '') !== '' && ($f['user'] ?? '') !== '' ? $f : null;
    }

    /**
     * Take the newest .xlsx/.csv/.txt from the shop's folder and load it.
     * Returns null when that file is already the one loaded.
     *
     * @return array{rows:int,check:array,file:string}|null
     */
    public static function fetch(TenantDistributorCatalogSubscription $sub): ?array
    {
        $tenantId = (string) $sub->tenant_id;
        $f = self::ftp($sub);
        if (! $f) {
            throw new RuntimeException('Add the FTP host, user and password first.');
        }
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('FTP is not available on this server.');
        }
        $conn = @ftp_ssl_connect((string) $f['host'], (int) ($f['port'] ?? 21), 20) ?: @ftp_connect((string) $f['host'], (int) ($f['port'] ?? 21), 20);
        if (! $conn) {
            throw new RuntimeException('Could not reach ' . $f['host'] . '.');
        }
        $tmp = null;
        try {
            if (! @ftp_login($conn, (string) $f['user'], (string) ($f['password'] ?? ''))) {
                throw new RuntimeException('The FTP login was refused. Check the user and password.');
            }
            ftp_pasv($conn, true);
            $dir = trim((string) ($f['folder'] ?? ''), " ");
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
                $t = @ftp_mdtm($conn, $n);
                $t = $t > 0 ? $t : 0;
                if ($t >= $bestTime) {
                    $best = $n;
                    $bestTime = $t;
                }
            }
            if ($best === null) {
                throw new RuntimeException('No .xlsx, .csv or .txt file in the folder.');
            }
            $file = BrandStockStore::file($tenantId, (string) $sub->distributor_code);
            $base = basename($best);
            if ($file && $file->file_name === $base && $bestTime > 0 && $file->file_modified_at && strtotime((string) $file->file_modified_at) >= $bestTime) {
                BrandStockStore::touchAttempt($tenantId, (string) $sub->distributor_code);
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'kona') . '.' . strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! @ftp_get($conn, $tmp, $best, FTP_BINARY)) {
                throw new RuntimeException('Could not download ' . $base . '.');
            }
            $r = self::load($tenantId, (string) $sub->distributor_code, $tmp, $base, 'ftp', $bestTime > 0 ? $bestTime : null);
            return $r + ['file' => $base];
        } finally {
            @ftp_close($conn);
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
