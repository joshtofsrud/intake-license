<?php

namespace Tests\Unit;

use App\Exceptions\OnlineOrderStockException;
use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\OrderService;
use App\Services\Tenant\OrderStock;
use PHPUnit\Framework\TestCase;

/** MARKER-ECOM-STOCK-GUARD / MARKER-ECOM-SETTINGS — the parts that need no database. */
class EcommerceStockGuardTest extends TestCase
{
    private function tenant(array $storefront = []): Tenant
    {
        $t = new Tenant();
        $t->forceFill(['id' => 't1', 'name' => 'Test Shop', 'settings' => ['storefront' => $storefront]]);
        return $t;
    }

    public function test_hidden_item_is_blocked_as_unavailable(): void
    {
        $item = (new TenantInventoryItem())->forceFill(['is_active' => true, 'show_online' => false]);
        $r = OrderStock::forTenant($this->tenant())->checkItem($item, 1, 'loc');
        $this->assertSame('blocked', $r['status']);
        $this->assertSame(OrderStock::REASON_UNAVAILABLE, $r['reason']);
        $this->assertSame('blocked', OrderStock::forTenant($this->tenant())->checkItem(null, 1, 'loc')['status']);
    }

    public function test_messages_say_what_happened_and_what_to_do(): void
    {
        $sold = OrderStock::message('Chain', ['reason' => OrderStock::REASON_SOLD_OUT, 'have' => 0, 'wanted' => 2]);
        $this->assertStringContainsString('just sold out', $sold);
        $this->assertStringContainsString('Remove it', $sold);

        $short = OrderStock::message('Chain', ['reason' => OrderStock::REASON_SHORT, 'have' => 1, 'wanted' => 3]);
        $this->assertStringContainsString('only have 1', $short);
        $this->assertStringContainsString('3 in your cart', $short);
    }

    public function test_exception_summary_joins_every_blocked_line(): void
    {
        $e = new OnlineOrderStockException(['a' => ['message' => 'One.'], 'b' => ['message' => 'Two.']]);
        $this->assertSame('One. Two.', $e->getMessage());
    }

    public function test_zip_parsing(): void
    {
        $this->assertSame(['99201', '99202', '99203'], OrderService::parseZips('99201, 99202  99203, 99201, abc, 1234'));
        $this->assertSame('99201', OrderService::zipFromAddress('12345 W Dean Ave, Spokane, WA 99201'));
        $this->assertSame('99201', OrderService::zipFromAddress('Spokane WA 99201-1234'));
        $this->assertNull(OrderService::zipFromAddress('no zip here'));
        $this->assertNull(OrderService::zipFromAddress(null));
    }

    public function test_config_defaults_and_overrides(): void
    {
        $d = OrderService::forTenant($this->tenant())->config();
        $this->assertTrue($d['pickup_enabled']);
        $this->assertTrue($d['auto_refund']);
        $this->assertFalse($d['tax_delivery_fee']);
        $this->assertNull($d['free_delivery_over_cents']);
        $this->assertSame(24, $d['hold_hours']);
        $this->assertSame([], $d['delivery_zips']);

        $o = OrderService::forTenant($this->tenant([
            'pickup_enabled' => false, 'auto_refund' => false, 'tax_delivery_fee' => true,
            'free_delivery_over_cents' => 7500, 'hold_hours' => 48, 'delivery_zips' => '99201, 99202',
        ]))->config();
        $this->assertFalse($o['pickup_enabled']);
        $this->assertFalse($o['auto_refund']);
        $this->assertTrue($o['tax_delivery_fee']);
        $this->assertSame(7500, $o['free_delivery_over_cents']);
        $this->assertSame(48, $o['hold_hours']);
        $this->assertSame(['99201', '99202'], $o['delivery_zips']);
    }

    public function test_wording_defaults_blank_and_overrides(): void
    {
        $d = OrderService::wording($this->tenant());
        $this->assertSame('Out of stock', $d['out_label']);
        $this->assertFalse($d['hide_out']);

        $o = OrderService::wording($this->tenant(['out_label' => 'Back soon', 'special_label' => '  ', 'out_display' => 'hide']));
        $this->assertSame('Back soon', $o['out_label']);
        $this->assertSame('Special order — usually a few days', $o['special_label']); // blank = standard
        $this->assertTrue($o['hide_out']);
    }
}
