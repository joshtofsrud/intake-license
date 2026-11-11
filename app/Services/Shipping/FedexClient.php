<?php

namespace App\Services\Shipping;

/** MARKER-SHIPPING-RATES: FedEx Rates and Transit Times API (OAuth client credentials). */
class FedexClient extends CarrierClient
{
    public static function code(): string { return 'fedex'; }
    public static function label(): string { return 'FedEx'; }

    public static function services(): array
    {
        return ['FEDEX_GROUND' => 'FedEx Ground', 'GROUND_HOME_DELIVERY' => 'FedEx Home Delivery', 'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
                'FEDEX_2_DAY' => 'FedEx 2Day', 'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight', 'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight'];
    }

    public static function fields(): array
    {
        return [
            'client_id'      => ['label' => 'API key', 'required' => true],
            'client_secret'  => ['label' => 'Secret key', 'secret' => true, 'required' => true],
            'account_number' => ['label' => 'FedEx account number', 'required' => true],
        ];
    }

    protected function host(): string { return $this->sandbox ? 'https://apis-sandbox.fedex.com' : 'https://apis.fedex.com'; }

    protected function tokenRequest(array $c): array
    {
        return ['/oauth/token', 'form', ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]];
    }

    protected function rateCalls(array $c, array $from, array $to, array $packages, array $services): array
    {
        return [['path' => '/rate/v1/rates/quotes', 'headers' => ['X-locale' => 'en_US'], 'payload' => self::build($c, $from, $to, $packages)]];
    }

    public static function build(array $c, array $from, array $to, array $packages): array
    {
        $items = array_map(function ($p) {
            $row = ['weight' => ['units' => 'LB', 'value' => self::lb($p['oz'])]];
            if (! empty($p['l'])) {
                $row['dimensions'] = ['length' => (int) ceil($p['l']), 'width' => (int) ceil($p['w']), 'height' => (int) ceil($p['h']), 'units' => 'IN'];
            }
            return $row;
        }, $packages);

        return [
            'accountNumber' => ['value' => (string) ($c['account_number'] ?? '')],
            'rateRequestControlParameters' => ['returnTransitTimes' => true],
            'requestedShipment' => [
                'shipper'   => ['address' => ['postalCode' => $from['zip'], 'countryCode' => $from['country'] ?? 'US']],
                'recipient' => ['address' => array_filter(['postalCode' => $to['zip'], 'stateOrProvinceCode' => $to['state'] ?? null, 'countryCode' => $to['country'] ?? 'US', 'residential' => true])],
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'rateRequestType' => ['ACCOUNT'],
                'packagingType' => 'YOUR_PACKAGING',
                'requestedPackageLineItems' => $items,
            ],
        ];
    }

    public static function parse(array $responses, array $services): array
    {
        $names = self::services();
        $out = [];
        foreach ($responses as $r) {
            foreach ($r['body']['output']['rateReplyDetails'] ?? [] as $d) {
                $code = (string) ($d['serviceType'] ?? '');
                if ($code === '' || ! in_array($code, $services, true)) continue;
                $amt = $d['ratedShipmentDetails'][0]['totalNetCharge'] ?? null;
                if (! is_numeric($amt)) continue;
                $days = null;
                if (! empty($d['commit']['dateDetail']['dayFormat'])) {
                    $days = max(1, (int) ceil((strtotime($d['commit']['dateDetail']['dayFormat']) - strtotime('today')) / 86400));
                }
                $out[] = ['carrier' => 'fedex', 'service' => $code, 'name' => $names[$code] ?? ($d['serviceName'] ?? $code), 'cents' => (int) round((float) $amt * 100), 'days' => $days];
            }
        }
        return $out;
    }
}
