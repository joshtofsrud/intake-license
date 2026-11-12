<?php

namespace Tests\Unit;

use App\Services\Tenant\ShipmentFlow;
use PHPUnit\Framework\TestCase;

class ShipmentFlowTest extends TestCase
{
    public function test_actions_follow_the_life_of_a_shipment(): void
    {
        $this->assertSame(['ship', 'special_order', 'cancel'], ShipmentFlow::actions('pending'));
        $this->assertContains('out_for_delivery', ShipmentFlow::actions('shipped'));
        $this->assertSame(['delivered'], ShipmentFlow::actions('out_for_delivery'));
        $this->assertSame([], ShipmentFlow::actions('delivered'));
        $this->assertSame([], ShipmentFlow::actions('cancelled'));
    }

    public function test_tracking_links(): void
    {
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999', ShipmentFlow::trackingUrl('ups', ' 1Z999 '));
        $this->assertStringContainsString('fedex.com', ShipmentFlow::trackingUrl('FedEx', '123'));
        $this->assertStringContainsString('usps.com', ShipmentFlow::trackingUrl('usps', '9400'));
        $this->assertNull(ShipmentFlow::trackingUrl('other', '123'));
        $this->assertNull(ShipmentFlow::trackingUrl('ups', ''));
    }

    public function test_order_status_follows_its_shipments(): void
    {
        $f = fn (array $s, string $cur = 'paid') => ShipmentFlow::orderStatus($s, $cur);
        $this->assertSame('paid', $f(['pending', 'pending']));
        $this->assertSame('fulfilling', $f(['shipped', 'pending']));
        $this->assertSame('fulfilled', $f(['shipped', 'out_for_delivery']));
        $this->assertSame('completed', $f(['delivered', 'delivered']));
        $this->assertSame('completed', $f(['delivered', 'cancelled']));
        $this->assertSame('fulfilling', $f(['delivered', 'special_order']));
        $this->assertSame('paid', $f(['cancelled']));
    }

    public function test_order_status_never_moves_backward_or_outside_the_queue(): void
    {
        $this->assertSame('fulfilled', ShipmentFlow::orderStatus(['shipped', 'pending'], 'fulfilled'));
        $this->assertSame('cancelled', ShipmentFlow::orderStatus(['shipped'], 'cancelled'));
        $this->assertSame('pending_payment', ShipmentFlow::orderStatus(['shipped'], 'pending_payment'));
    }
}
