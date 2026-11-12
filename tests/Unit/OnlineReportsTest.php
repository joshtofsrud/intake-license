<?php

namespace Tests\Unit;

use App\Services\Tenant\ReportBuilder\Sources\CartsSource;
use App\Services\Tenant\ReportBuilder\Sources\SalesSource;
use App\Services\Tenant\ReportBuilder\Sources\ShipmentsSource;
use PHPUnit\Framework\TestCase;

/** MARKER-ONLINE-REPORTS */
class OnlineReportsTest extends TestCase
{
    private function src(string $class)
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    public function test_shipping_comparisons_only_use_shipments_that_have_a_cost(): void
    {
        $s = $this->src(ShipmentsSource::class);
        // 4 shipments, charged $40 in all; 2 have a cost ($10 each, charged $12 each)
        $c = ['ships' => 4, 'units' => 6, 'charged' => 4000, 'cost' => 2000, 'chargedc' => 2400, 'uncosted' => 2, 'supunits' => 3, 'supships' => 1];
        $this->assertSame(40.0, $s->value('charged', $c));
        $this->assertSame(20.0, $s->value('cost', $c));
        $this->assertSame(4.0, $s->value('net', $c));            // $24 charged on costed shipments minus $20 cost
        $this->assertEqualsWithDelta(120.0, $s->value('recover', $c), 0.0001);
        $this->assertSame(2.0, $s->value('uncosted', $c));
        $this->assertEqualsWithDelta(50.0, $s->value('supshare', $c), 0.0001);
    }

    public function test_no_cost_entered_is_unknown_not_zero(): void
    {
        $s = $this->src(ShipmentsSource::class);
        $c = ['ships' => 3, 'units' => 3, 'charged' => 1500, 'cost' => 0, 'chargedc' => 0, 'uncosted' => 3, 'supunits' => 0, 'supships' => 0];
        $this->assertNull($s->value('cost', $c));
        $this->assertNull($s->value('net', $c));
        $this->assertNull($s->value('recover', $c));
        $this->assertSame(15.0, $s->value('charged', $c));
        $this->assertNull($s->value('supshare', ['units' => 0] + $c));
    }

    public function test_a_loss_on_shipping_shows_as_negative(): void
    {
        $s = $this->src(ShipmentsSource::class);
        $c = ['ships' => 1, 'units' => 1, 'charged' => 500, 'cost' => 1400, 'chargedc' => 500, 'uncosted' => 0, 'supunits' => 0, 'supships' => 0];
        $this->assertSame(-9.0, $s->value('net', $c));
        $this->assertEqualsWithDelta(35.714, $s->value('recover', $c), 0.001);
    }

    public function test_abandonment_rate_leaves_open_carts_out(): void
    {
        $s = $this->src(CartsSource::class);
        $c = ['started' => 10, 'paid' => 3, 'aband' => 1, 'open' => 6, 'abandval' => 4500, 'paidval' => 12000];
        $this->assertEqualsWithDelta(25.0, $s->value('abandp', $c), 0.0001);   // 1 of the 4 that ended
        $this->assertSame(45.0, $s->value('abandval', $c));
        $this->assertSame(6.0, $s->value('open', $c));
        $this->assertNull($s->value('abandp', ['paid' => 0, 'aband' => 0] + $c));
    }

    public function test_every_measure_a_source_lists_has_a_value(): void
    {
        foreach ([ShipmentsSource::class, CartsSource::class] as $class) {
            $s = $this->src($class);
            foreach (array_keys($s->meta()['measures']) as $m) {
                $this->assertNotNull(
                    $s->value($m, ['ships' => 2, 'units' => 2, 'charged' => 100, 'cost' => 50, 'chargedc' => 100, 'uncosted' => 0, 'supunits' => 1,
                        'supships' => 1, 'started' => 2, 'paid' => 1, 'aband' => 1, 'open' => 0, 'abandval' => 100, 'paidval' => 100]),
                    "$class::$m");
            }
        }
    }

    public function test_sales_can_be_split_by_where_they_were_sold(): void
    {
        $m = $this->src(SalesSource::class)->meta();
        $this->assertArrayHasKey('channel', $m['dims']);
        $this->assertArrayHasKey('channel', $m['filters']);
    }
}
