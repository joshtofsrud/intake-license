<?php

namespace Tests\Unit;

use App\Services\Shipping\FedexClient;
use App\Services\Shipping\UpsClient;
use App\Services\Shipping\UspsClient;
use PHPUnit\Framework\TestCase;

class CarrierClientsTest extends TestCase
{
    private array $from = ['zip' => '99201', 'state' => 'WA', 'country' => 'US'];
    private array $to   = ['zip' => '97401', 'state' => 'OR', 'country' => 'US'];

    public function test_ups_request_and_parse(): void
    {
        $req = UpsClient::build(['account_number' => 'A1B2C3'], $this->from, $this->to, [['oz' => 40, 'l' => 12, 'w' => 8, 'h' => 6], ['oz' => 8]]);
        $s = $req['RateRequest']['Shipment'];
        $this->assertSame('A1B2C3', $s['Shipper']['ShipperNumber']);
        $this->assertSame('2.5', $s['Package'][0]['PackageWeight']['Weight']);
        $this->assertSame('12', $s['Package'][0]['Dimensions']['Length']);
        $this->assertArrayNotHasKey('Dimensions', $s['Package'][1]);
        $this->assertSame('OR', $s['ShipTo']['Address']['StateProvinceCode']);

        $out = UpsClient::parse([['body' => ['RateResponse' => ['RatedShipment' => [
            ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '12.34'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '3']],
            ['Service' => ['Code' => '01'], 'TotalCharges' => ['MonetaryValue' => '55.00'], 'NegotiatedRateCharges' => ['TotalCharge' => ['MonetaryValue' => '41.10']]],
            ['Service' => ['Code' => '59'], 'TotalCharges' => ['MonetaryValue' => '30.00']],
        ]]]]], ['03', '01']);
        $this->assertCount(2, $out);
        $this->assertSame(1234, $out[0]['cents']);
        $this->assertSame(3, $out[0]['days']);
        $this->assertSame(4110, $out[1]['cents']);           // negotiated rate wins
    }

    public function test_ups_single_shipment_object_is_accepted(): void
    {
        $out = UpsClient::parse([['body' => ['RateResponse' => ['RatedShipment' =>
            ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '9.99']]]]]], ['03']);
        $this->assertSame(999, $out[0]['cents']);
    }

    public function test_fedex_request_and_parse(): void
    {
        $req = FedexClient::build(['account_number' => '123456789'], $this->from, $this->to, [['oz' => 24, 'l' => 10.2, 'w' => 8, 'h' => 4]]);
        $rs = $req['requestedShipment'];
        $this->assertSame('123456789', $req['accountNumber']['value']);
        $this->assertSame(1.5, $rs['requestedPackageLineItems'][0]['weight']['value']);
        $this->assertSame(11, $rs['requestedPackageLineItems'][0]['dimensions']['length']);
        $this->assertTrue($rs['recipient']['address']['residential']);

        $out = FedexClient::parse([['body' => ['output' => ['rateReplyDetails' => [
            ['serviceType' => 'FEDEX_GROUND', 'ratedShipmentDetails' => [['totalNetCharge' => 14.5]]],
            ['serviceType' => 'FEDEX_2_DAY', 'ratedShipmentDetails' => [['totalNetCharge' => 31]]],
            ['serviceType' => 'SMART_POST', 'ratedShipmentDetails' => [['totalNetCharge' => 5]]],
        ]]]]], ['FEDEX_GROUND', 'FEDEX_2_DAY']);
        $this->assertCount(2, $out);
        $this->assertSame(1450, $out[0]['cents']);
    }

    public function test_usps_sums_packages_and_drops_unpriced_classes(): void
    {
        $r = fn ($cls, $price, $n = 2) => ['meta' => ['class' => $cls, 'n' => $n], 'body' => ['totalBasePrice' => $price]];
        $out = UspsClient::parse([
            $r('USPS_GROUND_ADVANTAGE', 5.25), $r('USPS_GROUND_ADVANTAGE', 6.10),
            $r('PRIORITY_MAIL', 9.00),                                        // second package unpriced
        ], ['USPS_GROUND_ADVANTAGE', 'PRIORITY_MAIL']);
        $this->assertCount(1, $out);
        $this->assertSame('USPS_GROUND_ADVANTAGE', $out[0]['service']);
        $this->assertSame(1135, $out[0]['cents']);
    }

    public function test_usps_request_uses_a_size_when_the_item_has_none(): void
    {
        $p = UspsClient::build('PRIORITY_MAIL_EXPRESS', $this->from, $this->to, ['oz' => 10]);
        $this->assertSame(10.0, $p['length']);
        $this->assertSame('PA', $p['rateIndicator']);
        $this->assertSame('97401', $p['destinationZIPCode']);
        $this->assertSame(0.63, $p['weight']);
    }
}
