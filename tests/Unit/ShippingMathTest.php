<?php

namespace Tests\Unit;

use App\Services\Shipping\ItemShipSpec;
use App\Services\Shipping\PackageMath;
use App\Services\Shipping\RateTable;
use App\Services\Shipping\ShippingRates;
use App\Services\Shipping\Units;
use PHPUnit\Framework\TestCase;

class ShippingMathTest extends TestCase
{
    public function test_unit_conversion(): void
    {
        $this->assertSame(32.0, Units::toOunces(2, 'lb'));
        $this->assertSame(35.27, Units::toOunces(1, 'kg'));
        $this->assertSame(3.94, Units::toInches(10, 'cm'));
        $this->assertNull(Units::toOunces(0, 'lb'));
        $this->assertNull(Units::toOunces('abc', 'oz'));
    }

    public function test_dimension_shapes(): void
    {
        $this->assertSame([10.0, 8.0, 4.0], Units::dimensions(['Length' => 10, 'Width' => 8, 'Height' => 4]));
        $this->assertSame([10.0, 8.0, 4.0], Units::dimensions([10, 8, 4]));
        $this->assertSame([10.0, 8.0, 4.0], Units::dimensions('10 x 8 x 4'));
        $this->assertSame([10.0, 8.0, 4.0], Units::dimensions('{"l":10,"w":8,"h":4}'));
        $this->assertSame([12.0, 24.0, 36.0], Units::dimensions([1, 2, 3], 'ft'));
        $this->assertNull(Units::dimensions(['Length' => 10, 'Width' => 8]));
        $this->assertNull(Units::dimensions('big'));
    }

    public function test_rate_table(): void
    {
        $rows = [['upto_oz' => 32, 'cents' => 1000], ['upto_oz' => 16, 'cents' => 600], ['upto_oz' => 0, 'cents' => 5]];
        $this->assertSame(600, RateTable::price($rows, 10));
        $this->assertSame(600, RateTable::price($rows, 16));
        $this->assertSame(1000, RateTable::price($rows, 16.5));
        $this->assertNull(RateTable::price($rows, 33));
        $this->assertNull(RateTable::price([], 1));
    }

    public function test_pack_by_weight_when_no_boxes(): void
    {
        $p = PackageMath::pack([['oz' => 500], ['oz' => 500], ['oz' => 500]], []);
        $this->assertCount(2, $p);                       // 1120 oz cap
        $this->assertSame(1000.0, $p[0]['oz']);
    }

    public function test_pack_uses_smallest_box_and_tare(): void
    {
        $boxes = [
            ['name' => 'Large', 'l' => 20, 'w' => 16, 'h' => 12, 'max_oz' => 800, 'tare_oz' => 10],
            ['name' => 'Small', 'l' => 10, 'w' => 8, 'h' => 4, 'max_oz' => 160, 'tare_oz' => 4],
        ];
        $p = PackageMath::pack([['oz' => 20, 'l' => 8, 'w' => 6, 'h' => 3]], $boxes);
        $this->assertCount(1, $p);
        $this->assertSame('Small', $p[0]['box']);
        $this->assertSame(24.0, $p[0]['oz']);
    }

    public function test_pack_grows_the_box_and_splits_when_needed(): void
    {
        $boxes = [
            ['name' => 'Small', 'l' => 10, 'w' => 8, 'h' => 4, 'max_oz' => 160, 'tare_oz' => 0],
            ['name' => 'Large', 'l' => 20, 'w' => 16, 'h' => 12, 'max_oz' => 800, 'tare_oz' => 0],
        ];
        $two = PackageMath::pack([['oz' => 20, 'l' => 8, 'w' => 6, 'h' => 3], ['oz' => 20, 'l' => 8, 'w' => 6, 'h' => 3]], $boxes);
        $this->assertCount(1, $two);
        $this->assertSame('Large', $two[0]['box']);

        $tooBig = PackageMath::pack([['oz' => 20, 'l' => 40, 'w' => 6, 'h' => 3]], $boxes);   // fits no box
        $this->assertCount(1, $tooBig);
        $this->assertNull($tooBig[0]['box']);
        $this->assertSame(40.0, $tooBig[0]['l']);
    }

    public function test_default_weight_for_unknown_items(): void
    {
        $p = PackageMath::pack([['oz' => 0]], [], 24.0);
        $this->assertSame(24.0, $p[0]['oz']);
    }

    public function test_markup_is_percent_then_flat(): void
    {
        $o = ShippingRates::markup([['id' => 'a', 'cents' => 1000], ['id' => 'b', 'cents' => 2000]], 10, 150);
        $this->assertSame([1250, 2350], array_column($o, 'cents'));
        $this->assertSame([1000], array_column(ShippingRates::markup([['id' => 'a', 'cents' => 1000]], 0, 0), 'cents'));
    }

    public function test_free_over_makes_the_cheapest_free_and_the_rest_the_difference(): void
    {
        $o = ShippingRates::applyFree([['id' => 'g', 'cents' => 900], ['id' => 'x', 'cents' => 2400], ['id' => 'e', 'cents' => 5000]]);
        $this->assertSame([0, 1500, 4100], array_column($o, 'cents'));
        $this->assertSame([], ShippingRates::applyFree([]));
    }

    public function test_zip_and_flat_option(): void
    {
        $this->assertSame('99201', ShippingRates::zip('Spokane WA 99201-1234'));
        $this->assertSame('', ShippingRates::zip('none'));
        $this->assertSame(0, ShippingRates::flatOption(-5)['cents']);
        $this->assertSame('fallback', ShippingRates::flatOption(500, 'Standard shipping', 'fallback')['source']);
    }

    public function test_distributor_units_default_and_override(): void
    {
        $platform = ['units' => ['bti' => ['weight' => 'g', 'length' => 'mm'], 'bad' => ['weight' => 'stone', 'length' => 'furlong']]];
        $this->assertSame(['weight' => 'g', 'length' => 'mm'], ItemShipSpec::unitsFor('BTI', $platform));
        $this->assertSame(['weight' => 'lb', 'length' => 'in'], ItemShipSpec::unitsFor('qbp', $platform));
        $this->assertSame(['weight' => 'lb', 'length' => 'in'], ItemShipSpec::unitsFor('bad', $platform));
    }
}
