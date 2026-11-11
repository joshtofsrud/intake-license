<?php

namespace Tests\Unit;

use App\Services\Tenant\ShipmentMath;
use PHPUnit\Framework\TestCase;

class ShipmentMathTest extends TestCase
{
    public function test_allocate_sums_exactly(): void
    {
        $r = ShipmentMath::allocate(100, [1, 1, 1]);
        $this->assertSame(100, array_sum($r));
        $this->assertCount(3, $r);
    }

    public function test_allocate_zero_total(): void
    {
        $this->assertSame(0, array_sum(ShipmentMath::allocate(0, [2, 3])));
    }

    public function test_fees_per_shipment_and_supplier_fee(): void
    {
        $f = ShipmentMath::fees(['a' => 'shop', 'b' => 'supplier'], 1000, null, 800, 1200);
        $this->assertSame(['a' => 800, 'b' => 1200], $f);
        $f = ShipmentMath::fees(['a' => 'shop', 'b' => 'supplier'], 1000, null, 800, null);
        $this->assertSame(['a' => 800, 'b' => 800], $f);
    }

    public function test_free_over_applies_to_every_shipment(): void
    {
        $f = ShipmentMath::fees(['a' => 'shop', 'b' => 'supplier'], 5000, 5000, 800, 1200);
        $this->assertSame(['a' => 0, 'b' => 0], $f);
        $f = ShipmentMath::fees(['a' => 'shop'], 4999, 5000, 800, null);
        $this->assertSame(['a' => 800], $f);
    }

    public function test_distribute_hands_segments_to_lines_in_order(): void
    {
        $r = ShipmentMath::distribute([10 => 2, 11 => 3], [
            ['key' => 'shop', 'qty' => 3],
            ['key' => 'sup', 'qty' => 2, 'special' => 1],
        ]);
        $this->assertSame([['key' => 'shop', 'qty' => 2, 'special' => 0]], $r[10]);
        $this->assertSame(
            [['key' => 'shop', 'qty' => 1, 'special' => 0], ['key' => 'sup', 'qty' => 2, 'special' => 1]],
            $r[11]
        );
    }
}
