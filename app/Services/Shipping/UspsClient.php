<?php

namespace App\Services\Shipping;

/**
 * MARKER-SHIPPING-RATES: USPS Domestic Prices API (OAuth client credentials). One price
 * request per package and mail class; a class's price is the sum over the packages.
 */
class UspsClient extends CarrierClient
{
    /** USPS needs a size on every package; used (and noted) when an item has none. */
    public const FALLBACK_DIMS = [10.0, 8.0, 4.0];

    public static function code(): string { return 'usps'; }
    public static function label(): string { return 'USPS'; }

    public static function services(): array
    {
        return ['USPS_GROUND_ADVANTAGE' => 'USPS Ground Advantage', 'PRIORITY_MAIL' => 'USPS Priority Mail', 'PRIORITY_MAIL_EXPRESS' => 'USPS Priority Mail Express'];
    }

    public static function fields(): array
    {
        return [
            'client_id'     => ['label' => 'Consumer key', 'required' => true],
            'client_secret' => ['label' => 'Consumer secret', 'secret' => true, 'required' => true],
        ];
    }

    protected function host(): string { return $this->sandbox ? 'https://apis-tp.usps.com' : 'https://apis.usps.com'; }

    protected function tokenRequest(array $c): array
    {
        return ['/oauth2/v3/token', 'json', ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]];
    }

    protected function rateCalls(array $c, array $from, array $to, array $packages, array $services): array
    {
        $calls = [];
        foreach ($services as $cls) {
            foreach (array_values($packages) as $i => $p) {
                $calls[] = ['path' => '/prices/v3/base-rates/search', 'payload' => self::build($cls, $from, $to, $p), 'meta' => ['class' => $cls, 'n' => count($packages)]];
            }
        }
        return $calls;
    }

    public static function build(string $class, array $from, array $to, array $p): array
    {
        [$l, $w, $h] = ! empty($p['l']) ? [$p['l'], $p['w'], $p['h']] : self::FALLBACK_DIMS;
        return [
            'originZIPCode' => substr(preg_replace('/\D/', '', $from['zip']), 0, 5),
            'destinationZIPCode' => substr(preg_replace('/\D/', '', $to['zip']), 0, 5),
            'weight' => self::lb($p['oz']),
            'length' => $l, 'width' => $w, 'height' => $h,
            'mailClass' => $class,
            'processingCategory' => 'MACHINABLE',
            'rateIndicator' => $class === 'PRIORITY_MAIL_EXPRESS' ? 'PA' : 'SP',
            'destinationEntryFacility' => 'NONE',
            'priceType' => 'RETAIL',
            'mailingDate' => date('Y-m-d'),
        ];
    }

    public static function parse(array $responses, array $services): array
    {
        $names = self::services();
        $sum = []; $count = []; $want = [];
        foreach ($responses as $r) {
            $cls = $r['meta']['class'] ?? null;
            $price = $r['body']['totalBasePrice'] ?? ($r['body']['rates'][0]['price'] ?? null);
            if (! $cls || ! in_array($cls, $services, true) || ! is_numeric($price)) continue;
            $sum[$cls] = ($sum[$cls] ?? 0) + (float) $price;
            $count[$cls] = ($count[$cls] ?? 0) + 1;
            $want[$cls] = (int) ($r['meta']['n'] ?? 1);
        }
        $out = [];
        foreach ($sum as $cls => $total) {
            if ($count[$cls] < $want[$cls]) continue;           // one package unpriced = the class is not offered
            $out[] = ['carrier' => 'usps', 'service' => $cls, 'name' => $names[$cls], 'cents' => (int) round($total * 100),
                      'days' => ['USPS_GROUND_ADVANTAGE' => 5, 'PRIORITY_MAIL' => 3, 'PRIORITY_MAIL_EXPRESS' => 1][$cls] ?? null];
        }
        return $out;
    }
}
