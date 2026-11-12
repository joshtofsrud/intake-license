<?php

namespace Tests\Unit;

use App\Services\Feeds\GoogleFeed;
use PHPUnit\Framework\TestCase;

class GoogleFeedTest extends TestCase
{
    private function facts(array $o = []): array
    {
        return array_merge([
            'id' => 'abc', 'name' => 'Chain', 'brand' => 'Shimano', 'description' => 'A durable twelve speed chain for everyday riding.',
            'link' => 'https://s/shop/chain-abc', 'images' => ['https://s/a.jpg', 'https://s/b.jpg'], 'price_cents' => 2999, 'currency' => 'usd',
            'available' => true, 'upc' => '012345678905', 'mpn' => 'CN-1', 'google_category' => '1234', 'product_type' => 'Parts > Chains',
            'weight_oz' => 8.0, 'ship_cents' => 799, 'exclude' => false, 'shop' => 'Shop',
        ], $o);
    }

    public function test_barcodes_need_the_right_length_and_check_digit(): void
    {
        $this->assertSame('012345678905', GoogleFeed::gtinValid('0-12345-67890-5'));
        $this->assertNull(GoogleFeed::gtinValid('012345678906'));
        $this->assertNull(GoogleFeed::gtinValid('123'));
        $this->assertNull(GoogleFeed::gtinValid(null));
    }

    public function test_a_complete_item_is_in_the_feed_with_the_right_fields(): void
    {
        $v = GoogleFeed::evaluate($this->facts());
        $this->assertTrue($v['ok']);
        $this->assertSame([], $v['warnings']);
        $a = $v['attrs'];
        $this->assertSame('29.99 USD', $a['price']);
        $this->assertSame('in_stock', $a['availability']);
        $this->assertSame('012345678905', $a['gtin']);
        $this->assertSame('0.5 lb', $a['shipping_weight']);
        $this->assertSame('7.99 USD', $a['shipping']['price']);
        $this->assertSame(['https://s/b.jpg'], $a['additional_image_link']);
        $this->assertArrayNotHasKey('identifier_exists', $a);
    }

    public function test_items_without_price_photo_or_that_are_left_out_are_excluded_with_reasons(): void
    {
        $v = GoogleFeed::evaluate($this->facts(['price_cents' => 0, 'images' => [], 'exclude' => true]));
        $this->assertFalse($v['ok']);
        $this->assertContains('No price', $v['reasons']);
        $this->assertContains('No photo', $v['reasons']);
        $this->assertContains('You left it out of the Google feed', $v['reasons']);
    }

    public function test_missing_identifiers_warn_and_say_so_to_google(): void
    {
        $v = GoogleFeed::evaluate($this->facts(['upc' => '', 'mpn' => '', 'brand' => '', 'google_category' => '', 'description' => '']));
        $this->assertTrue($v['ok']);
        foreach (['No barcode', 'No brand', 'No Google category', 'Short or missing description'] as $w) $this->assertContains($w, $v['warnings']);
        $this->assertSame('no', $v['attrs']['identifier_exists']);
        $this->assertArrayNotHasKey('gtin', $v['attrs']);
    }

    public function test_out_of_stock_and_no_shipping_fee(): void
    {
        $a = GoogleFeed::evaluate($this->facts(['available' => false, 'ship_cents' => null]))['attrs'];
        $this->assertSame('out_of_stock', $a['availability']);
        $this->assertArrayNotHasKey('shipping', $a);
    }

    public function test_xml_is_escaped_and_survives_control_characters(): void
    {
        $a = GoogleFeed::evaluate($this->facts(['name' => "Tom & Jerry's <Chain>\x01", 'description' => 'Fits "all" bikes & more, designed for daily use.']))['attrs'];
        $x = GoogleFeed::itemXml($a);
        $this->assertStringContainsString('Shimano Tom &amp; Jerry&apos;s &lt;Chain&gt;', $x);
        $this->assertStringNotContainsString("\x01", $x);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML('<rss xmlns:g="' . GoogleFeed::NS . '"><channel>' . $x . '</channel></rss>'));
    }

    public function test_a_whole_feed_is_well_formed_xml(): void
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="' . GoogleFeed::NS . '"><channel>'
           . GoogleFeed::itemXml(GoogleFeed::evaluate($this->facts())['attrs']) . GoogleFeed::foot();
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(str_replace("</channel>\n</rss>\n", '', $x) . '</channel></rss>'));
        $this->assertSame(1, $doc->getElementsByTagNameNS(GoogleFeed::NS, 'gtin')->length);
    }
}
