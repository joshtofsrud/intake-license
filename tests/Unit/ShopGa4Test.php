<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\ShopGa4;
use PHPUnit\Framework\TestCase;

class ShopGa4Test extends TestCase
{
    private function tenant(array $settings): Tenant
    {
        $t = new Tenant();
        $t->setRawAttributes(['settings' => json_encode($settings)], true);
        return $t;
    }

    public function test_an_item_is_in_ga4_shape(): void
    {
        $i = ShopGa4::item(['id' => 'abc', 'name' => 'Chain', 'brand' => 'Shimano', 'category' => 'Parts', 'price_cents' => 2999, 'qty' => 2]);
        $this->assertSame(['item_id' => 'abc', 'item_name' => 'Chain', 'price' => 29.99, 'quantity' => 2, 'item_brand' => 'Shimano', 'item_category' => 'Parts'], $i);
        $this->assertArrayNotHasKey('item_brand', ShopGa4::item(['id' => 'a', 'name' => 'n', 'price_cents' => 100, 'brand' => ' ']));
        $this->assertSame(1, ShopGa4::item(['id' => 'a', 'name' => 'n', 'qty' => 0])['quantity']);
    }

    public function test_purchase_keeps_tax_and_shipping_out_of_value(): void
    {
        $p = ShopGa4::purchase([
            'number' => 'ORD-1', 'subtotal_cents' => 10000, 'discount_cents' => 1000, 'tax_cents' => 810, 'shipping_cents' => 799,
            'currency' => 'usd', 'coupon' => 'SAVE10',
            'items' => [['id' => 'a', 'name' => 'Chain', 'price_cents' => 5000, 'qty' => 2]],
        ]);
        $this->assertSame('ORD-1', $p['transaction_id']);
        $this->assertSame(90.0, $p['value']);
        $this->assertSame(8.1, $p['tax']);
        $this->assertSame(7.99, $p['shipping']);
        $this->assertSame('USD', $p['currency']);
        $this->assertSame('SAVE10', $p['coupon']);
        $this->assertSame(50.0, $p['items'][0]['price']);
    }

    public function test_events_need_an_id_and_respect_the_switch(): void
    {
        $this->assertFalse(ShopGa4::on($this->tenant([])));
        $this->assertFalse(ShopGa4::on($this->tenant(['analytics_ga4_id' => 'bogus'])));
        $this->assertTrue(ShopGa4::on($this->tenant(['analytics_ga4_id' => 'G-ABCD1234'])));
        $this->assertFalse(ShopGa4::on($this->tenant(['analytics_ga4_id' => 'G-ABCD1234', 'analytics_ecom_on' => false])));
        $this->assertSame('', ShopGa4::id($this->tenant(['analytics_ga4_id' => 'UA-1234567'])));
    }

    public function test_the_visitors_ids_are_read_from_google_cookies(): void
    {
        $_COOKIE['_ga'] = 'GA1.1.123456789.1700000000';
        $_COOKIE['_ga_ABCD1234'] = 'GS1.1.1700000001.3.1.1700000002.0.0.0';
        $this->assertSame(['client_id' => '123456789.1700000000', 'session_id' => '1700000001'], ShopGa4::clientFromCookies('G-ABCD1234'));
        $_COOKIE['_ga'] = 'junk';
        $this->assertNull(ShopGa4::clientFromCookies('G-ABCD1234'));
        unset($_COOKIE['_ga'], $_COOKIE['_ga_ABCD1234']);
    }
}
