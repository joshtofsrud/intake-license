<?php

namespace App\Services\Shipping;

/** MARKER-SHIPPING-RATES: UPS Rating API (OAuth client credentials, "Shop" request). */
class UpsClient extends CarrierClient
{
    public static function code(): string { return 'ups'; }
    public static function label(): string { return 'UPS'; }

    public static function services(): array
    {
        return ['03' => 'UPS Ground', '12' => 'UPS 3 Day Select', '02' => 'UPS 2nd Day Air', '13' => 'UPS Next Day Air Saver', '01' => 'UPS Next Day Air'];
    }

    public static function fields(): array
    {
        return [
            'client_id'      => ['label' => 'Client ID', 'required' => true],
            'client_secret'  => ['label' => 'Client secret', 'secret' => true, 'required' => true],
            'account_number' => ['label' => 'UPS account number', 'required' => true],
        ];
    }

    protected function host(): string { return $this->sandbox ? 'https://wwwcie.ups.com' : 'https://onlinetools.ups.com'; }

    protected function tokenRequest(array $c): array
    {
        return ['/security/v1/oauth/token', 'basic', ['grant_type' => 'client_credentials']];
    }

    protected function rateCalls(array $c, array $from, array $to, array $packages, array $services): array
    {
        return [['path' => '/api/rating/v2409/Shop?additionalinfo=timeintransit', 'headers' => ['transId' => bin2hex(random_bytes(8)), 'transactionSrc' => 'intake'],
                 'payload' => self::build($c, $from, $to, $packages)]];
    }

    public static function build(array $c, array $from, array $to, array $packages): array
    {
        $addr = fn ($a, $res = false) => array_filter([
            'PostalCode' => $a['zip'], 'StateProvinceCode' => $a['state'] ?? null, 'CountryCode' => $a['country'] ?? 'US',
            'ResidentialAddressIndicator' => $res ? '' : null,
        ], fn ($v) => $v !== null);
        $acct = (string) ($c['account_number'] ?? '');
        $pk = array_map(function ($p) {
            $row = ['PackagingType' => ['Code' => '02'],
                    'PackageWeight' => ['UnitOfMeasurement' => ['Code' => 'LBS'], 'Weight' => (string) self::lb($p['oz'])]];
            if (! empty($p['l'])) {
                $row['Dimensions'] = ['UnitOfMeasurement' => ['Code' => 'IN'], 'Length' => (string) $p['l'], 'Width' => (string) $p['w'], 'Height' => (string) $p['h']];
            }
            return $row;
        }, $packages);

        return ['RateRequest' => [
            'Request' => ['TransactionReference' => ['CustomerContext' => 'intake']],
            'Shipment' => [
                'Shipper'  => ['Name' => 'Shop', 'ShipperNumber' => $acct, 'Address' => $addr($from)],
                'ShipTo'   => ['Name' => 'Customer', 'Address' => $addr($to, true)],
                'ShipFrom' => ['Name' => 'Shop', 'Address' => $addr($from)],
                'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => ''],
                'Package'  => $pk,
            ],
        ]];
    }

    public static function parse(array $responses, array $services): array
    {
        $names = self::services();
        $out = [];
        foreach ($responses as $r) {
            $rated = $r['body']['RateResponse']['RatedShipment'] ?? [];
            if ($rated && array_is_list($rated) === false) $rated = [$rated];
            foreach ($rated as $s) {
                $code = (string) ($s['Service']['Code'] ?? '');
                if ($code === '' || ! in_array($code, $services, true) || ! isset($names[$code])) continue;
                $amt = $s['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? $s['TotalCharges']['MonetaryValue'] ?? null;
                if (! is_numeric($amt)) continue;
                $days = $s['GuaranteedDelivery']['BusinessDaysInTransit'] ?? ($s['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit'] ?? null);
                $out[] = ['carrier' => 'ups', 'service' => $code, 'name' => $names[$code], 'cents' => (int) round((float) $amt * 100), 'days' => is_numeric($days) ? (int) $days : null];
            }
        }
        return $out;
    }
}
