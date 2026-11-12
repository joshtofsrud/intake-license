<?php

namespace App\Services\Shipping;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-SHIPPING-RATES: what one shipment costs to send, as options the shopper picks from.
 *
 *   flat     the shop's fee per shipment (as before)
 *   weight   the shop's weight table, on the shipment's packed weight
 *   carrier  live UPS / USPS / FedEx rates on the shop's own accounts, plus the shop's markup
 *
 * Whenever a table is too short or every carrier fails, the shop's flat fee is offered
 * instead (rate_source "fallback") — an order is never blocked by a carrier outage.
 *
 * Option  ['id' =>, 'name' =>, 'cents' =>, 'days' => ?int, 'carrier' => ?, 'service' => ?, 'source' => flat|table|carrier|fallback]
 */
class ShippingRates
{
    public const MODES = ['flat', 'weight', 'carrier'];

    /* ------------------------------ settings ------------------------------ */

    public static function config(Tenant $t): array
    {
        $s = (array) (($t->settings ?? [])['storefront'] ?? []);
        $mode = (string) ($s['ship_mode'] ?? 'flat');   // MARKER-SHIP-MODE-DEFAULT: a shop that never saved a mode has no key
        $mode = in_array($mode, self::MODES, true) ? $mode : 'flat';
        $carriers = [];
        foreach (CarrierClient::registry() as $code => $cls) {
            $c = (array) ($s['carriers'][$code] ?? []);
            $carriers[$code] = [
                'enabled'  => (bool) ($c['enabled'] ?? false),
                'sandbox'  => (bool) ($c['sandbox'] ?? false),
                'services' => array_values(array_intersect((array) ($c['services'] ?? array_keys($cls::services())), array_keys($cls::services()))),
                'has_creds' => self::creds($t, $code) !== null,
            ];
        }
        return [
            'mode'        => $mode,
            'table'       => RateTable::clean((array) ($s['ship_table'] ?? [])),
            'boxes'       => PackageMath::cleanBoxes((array) ($s['ship_boxes'] ?? [])),
            'default_oz'  => (float) ($s['ship_default_oz'] ?? 0) > 0 ? (float) $s['ship_default_oz'] : 16.0,
            'markup_pct'  => max(0.0, min(100.0, (float) ($s['ship_markup_pct'] ?? 0))),
            'markup_cents' => max(0, (int) ($s['ship_markup_cents'] ?? 0)),
            'from_zip'    => self::zip((string) ($s['ship_from_zip'] ?? '')) ?: self::zip((string) ($t->defaultLocation?->postal_code ?? '')),
            'carriers'    => $carriers,
        ];
    }

    public static function zip(string $raw): string
    {
        return preg_match('/\d{5}/', $raw, $m) ? $m[0] : '';
    }

    /** Decrypted credentials for a carrier, or null when not all required fields are saved. */
    public static function creds(Tenant $t, string $code): ?array
    {
        $cls = CarrierClient::registry()[$code] ?? null;
        if (! $cls) return null;
        $raw = (array) ((($t->settings ?? [])['storefront']['carriers'][$code] ?? []));
        $out = [];
        foreach ($cls::fields() as $k => $f) {
            $v = (string) ($raw[$k] ?? '');
            if ($v !== '' && ! empty($f['secret'])) {
                try { $v = Crypt::decryptString($v); } catch (\Throwable) { $v = ''; }
            }
            if ($v === '' && ! empty($f['required'])) return null;
            $out[$k] = $v;
        }
        return $out;
    }

    /** What to write for a carrier, keeping a saved secret when the form left it blank. */
    public static function credsForSave(Tenant $t, string $code, array $in): array
    {
        $cls = CarrierClient::registry()[$code];
        $old = (array) ((($t->settings ?? [])['storefront']['carriers'][$code] ?? []));
        $out = [];
        foreach ($cls::fields() as $k => $f) {
            $v = trim((string) ($in[$k] ?? ''));
            if (! empty($f['secret'])) {
                $out[$k] = $v !== '' ? Crypt::encryptString($v) : (string) ($old[$k] ?? '');
            } else {
                $out[$k] = $v;
            }
        }
        $out['enabled']  = (bool) ($in['enabled'] ?? false);
        $out['sandbox']  = (bool) ($in['sandbox'] ?? false);
        $out['services'] = array_values(array_intersect((array) ($in['services'] ?? []), array_keys($cls::services())));
        return $out;
    }

    /* ------------------------------ pure pieces ------------------------------ */

    public static function markup(array $options, float $pct, int $cents): array
    {
        return array_map(function ($o) use ($pct, $cents) {
            $o['cents'] = (int) round($o['cents'] * (1 + $pct / 100)) + $cents;
            return $o;
        }, $options);
    }

    /** Free shipping over the threshold: the cheapest option becomes free, the rest cost only the difference. */
    public static function applyFree(array $options): array
    {
        if (! $options) return $options;
        $min = min(array_column($options, 'cents'));
        return array_map(function ($o) use ($min) {
            $o['cents'] = max(0, $o['cents'] - $min);
            return $o;
        }, $options);
    }

    public static function flatOption(int $cents, string $name = 'Standard shipping', string $source = 'flat'): array
    {
        return ['id' => 'flat', 'name' => $name, 'cents' => max(0, $cents), 'days' => null, 'carrier' => null, 'service' => null, 'source' => $source];
    }

    /* ------------------------------ the quote ------------------------------ */

    /**
     * @param array $spec ['from_zip' => ?string, 'units' => [['oz','l','w','h'], ...]]
     * @param array $to   ['zip' => ?, 'state' => ?]
     * @return array{options:array,packages:array,note:?string,needs_address:bool,mode:string}
     */
    public static function quote(Tenant $t, array $spec, array $to, int $flatCents, bool $free = false): array
    {
        $cfg = self::config($t);
        $mode = $cfg['mode'];
        $packages = PackageMath::pack($spec['units'] ?? [], $cfg['boxes'], $cfg['default_oz']);
        $base = ['packages' => $packages, 'note' => null, 'needs_address' => false, 'mode' => $mode];

        if ($mode === 'flat') {
            return $base + ['options' => [$free ? self::flatOption(0) : self::flatOption($flatCents)]];
        }

        $zip = self::zip((string) ($to['zip'] ?? ''));
        if ($zip === '') {
            return ['options' => [], 'needs_address' => true] + $base;
        }

        $options = [];
        $note = null;
        if ($mode === 'weight') {
            $oz = array_sum(array_column($packages, 'oz'));
            $price = RateTable::price($cfg['table'], (float) $oz);
            if ($price !== null) {
                $options = [['id' => 'table', 'name' => 'Shipping', 'cents' => $price, 'days' => null, 'carrier' => null, 'service' => null, 'source' => 'table']];
            } else {
                $note = $cfg['table'] ? 'Heavier than the shop\'s weight table.' : 'The shop has no weight table yet.';
            }
        } else {
            [$options, $note] = self::carrierOptions($t, $cfg, $spec, $to + ['zip' => $zip], $packages);
            $options = self::markup($options, $cfg['markup_pct'], $cfg['markup_cents']);
            usort($options, fn ($a, $b) => $a['cents'] <=> $b['cents']);
        }

        if (! $options) {
            $options = [self::flatOption($flatCents, 'Standard shipping', 'fallback')];
            return ['options' => $free ? self::applyFree($options) : $options, 'note' => $note] + $base;
        }
        return ['options' => $free ? self::applyFree($options) : $options, 'note' => $note] + $base;
    }

    /** @return array{0:array,1:?string} options, and a note when something went wrong */
    private static function carrierOptions(Tenant $t, array $cfg, array $spec, array $to, array $packages): array
    {
        $from = self::zip((string) ($spec['from_zip'] ?? ''));
        if ($from === '') return [[], 'No ship-from ZIP for this shipment.'];

        $all = []; $errors = [];
        foreach (CarrierClient::registry() as $code => $cls) {
            $c = $cfg['carriers'][$code];
            if (! $c['enabled'] || ! $c['services'] || ! ($creds = self::creds($t, $code))) continue;
            $key = 'ship_rates:' . md5(json_encode([$t->id, $code, $from, $to['zip'], $to['state'] ?? '', $packages, $c['services'], $c['sandbox']]));
            $got = Cache::get($key);
            if ($got === null) {
                try {
                    $got = (new $cls($c['sandbox']))->rates($creds, ['zip' => $from, 'state' => null, 'country' => 'US'],
                        ['zip' => $to['zip'], 'state' => $to['state'] ?? null, 'country' => 'US'], $packages, $c['services']);
                    Cache::put($key, $got, 600);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    Log::warning('shipping rates failed', ['tenant' => $t->id, 'carrier' => $code, 'error' => $e->getMessage()]);
                    continue;
                }
            }
            foreach ($got as $o) {
                $all[] = $o + ['id' => $o['carrier'] . ':' . $o['service'], 'source' => 'carrier'];
            }
        }
        return [$all, $all ? null : ($errors ? implode(' ', $errors) : 'No carrier is set up for rates.')];
    }
}
