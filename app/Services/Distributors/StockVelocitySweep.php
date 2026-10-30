<?php

namespace App\Services\Distributors;

use App\Models\PlatformDistributorConnection;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-VELOCITY-SWEEP: one nightly stock reading of each distributor's
 * WHOLE catalog, using the master admin login (the same one that builds the
 * shared catalog), fed into StockVelocityRecorder.
 *
 * How each distributor is read, by what its API allows:
 *   BTI, SHIMANO  one feed download covers every item (Shimano reuses its cached feed)
 *   HLC           stock is asked for in batches of 50 SKUs, with a pause between calls
 *   QBP           one call per warehouse (no arrival dates on that call); the
 *                 per-SKU call is far too many requests for a whole catalog.
 *                 Warehouse codes come from what shops' syncs have already seen.
 * The per-shop sync keeps recording too; the recorder ignores a second reading
 * of the same item within 6 hours, so the sweep (run first) is the night's reading.
 */
class StockVelocitySweep
{
    public const HLC_BATCH = 50;
    public const HLC_PAUSE_MS = 250;

    public function __construct(
        private DistributorRegistry $registry,
        private TenantDistributorSyncService $sync,
        private StockVelocityRecorder $recorder,
    ) {}

    /** @return array{code:string, items:int, read:int, counted:int, changed:int, new:int, calls:int, note:?string} */
    public function run(string $code, int $maxCalls = 5000): array
    {
        $code = strtoupper($code);
        $out = ['code' => $code, 'items' => 0, 'read' => 0, 'counted' => 0, 'changed' => 0, 'new' => 0, 'calls' => 0, 'note' => null];

        $conn = PlatformDistributorConnection::where('distributor_code', $code)->first();
        if (! $conn || ! $conn->api_key || ! $conn->is_active) {
            $out['note'] = 'No active master admin login for ' . $code . '.';
            return $out;
        }
        $adapter = $this->registry->make($code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
        if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
            $adapter->setAuthStyle($conn->auth_style);
        }

        // only items in the shared catalog are recorded
        $catalog = DB::table('platform_distributor_catalogs')
            ->where('distributor_code', $code)->where('is_active', true)->whereNull('deleted_at')
            ->pluck('id', 'distributor_variant_no')->all();
        $out['items'] = count($catalog);
        if (! $catalog) {
            $out['note'] = 'No catalog rows for ' . $code . '.';
            return $out;
        }

        $avail = [];
        $wh = [];
        $hasEta = true;

        switch ($code) {
            case 'BTI':
            case 'SHIMANO':
                $resp = $adapter->inventory([]);
                $out['calls'] = 1;
                $avail = $this->sync->normalizeInventory($resp);
                $wh = $this->sync->normalizeWarehouses($resp);
                break;

            case 'QBP':
                $hasEta = false;
                $codes = $this->qbpWarehouses();
                if (! $codes) {
                    $out['note'] = 'No QBP warehouse codes seen yet; QBP is read by the shop sync only.';
                    return $out;
                }
                foreach ($codes as $w) {
                    if ($out['calls'] >= $maxCalls) break;
                    $out['calls']++;
                    foreach ($adapter->inventoryByWarehouse($w) as $sku => $qty) {
                        $avail[(string) $sku] = ($avail[(string) $sku] ?? 0) + (int) $qty;
                    }
                }
                break;

            default: // HLC and any per-SKU distributor
                foreach (array_chunk(array_keys($catalog), self::HLC_BATCH) as $chunk) {
                    if ($out['calls'] >= $maxCalls) {
                        $out['note'] = "Stopped at {$maxCalls} calls; the rest are read tomorrow night.";
                        break;
                    }
                    $out['calls']++;
                    try {
                        $resp = $adapter->inventory(array_map('strval', $chunk));
                        $avail += $this->sync->normalizeInventory($resp);
                        $wh += $this->sync->normalizeWarehouses($resp);
                    } catch (\Throwable $e) {
                        $out['note'] = 'Some batches failed: ' . substr($e->getMessage(), 0, 160);
                    }
                    usleep(self::HLC_PAUSE_MS * 1000);
                }
        }

        $today = now()->toDateString();
        $readings = [];
        foreach ($avail as $vno => $qty) {
            $vno = (string) $vno;
            if (! isset($catalog[$vno]) || $qty === null) continue;
            $readings[$vno] = [
                'avail' => (int) $qty,
                'catalog_id' => $catalog[$vno],
                'has_eta_info' => $hasEta && isset($wh[$vno]),
                'eta' => $hasEta ? StockVelocityRecorder::earliestEta($wh[$vno] ?? null, $today) : null,
            ];
        }

        $r = $this->recorder->record($code, $readings);
        return array_merge($out, ['read' => $r['read'], 'counted' => $r['counted'], 'changed' => $r['changed'], 'new' => $r['new']]);
    }

    /** QBP warehouse codes that shops' own syncs have reported (MARKER-SUPPLY live_warehouses). */
    public function qbpWarehouses(): array
    {
        $codes = [];
        DB::table('tenant_inventory_item_vendors')
            ->where('distributor_code', 'QBP')->whereNotNull('live_warehouses')
            ->orderByDesc('live_checked_at')->limit(2000)
            ->pluck('live_warehouses')
            ->each(function ($json) use (&$codes) {
                foreach ((array) (is_string($json) ? json_decode($json, true) : $json) as $w) {
                    $c = trim((string) ($w['c'] ?? ''));
                    if ($c !== '') $codes[$c] = true;
                }
            });
        return array_keys($codes);
    }
}
