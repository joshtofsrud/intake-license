<?php

namespace Tests\Unit;

use App\Support\ItemSeo;
use PHPUnit\Framework\TestCase;

class ItemSeoTest extends TestCase
{
    private const ID = '0a1b2c3d-1111-2222-3333-444455556666';

    public function test_addresses_are_readable_and_the_id_still_finds_the_item(): void
    {
        $this->assertSame('shimano-deore-m6100-12-speed-chain-' . self::ID, ItemSeo::param(self::ID, 'Shimano Deore M6100 12-Speed Chain!'));
        $this->assertSame(self::ID, ItemSeo::param(self::ID, '!!!'));
        $this->assertSame(self::ID, ItemSeo::idFromParam('anything-at-all-' . self::ID));
        $this->assertSame(self::ID, ItemSeo::idFromParam(self::ID));
        $this->assertNull(ItemSeo::idFromParam('not-an-id'));
        $this->assertSame('/shop/cafe-' . self::ID, ItemSeo::path(self::ID, 'Café'));
    }

    public function test_long_names_are_cut_at_a_word_boundary_of_the_slug(): void
    {
        $slug = ItemSeo::slug(str_repeat('word ', 40));
        $this->assertLessThanOrEqual(60, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function test_title_and_description_rules(): void
    {
        $this->assertSame('Custom', ItemSeo::title(' Custom ', 'Chain', 'Shimano'));
        $this->assertSame('Shimano Chain', ItemSeo::title(null, 'Chain', 'Shimano'));
        $this->assertSame('Shimano Chain', ItemSeo::title(null, 'Shimano Chain', 'Shimano'));
        $this->assertSame('Ours', ItemSeo::description('Ours', 'item', null, 'N', null, 'Shop'));
        $this->assertSame('A great chain.', ItemSeo::description(null, 'A <b>great</b>  chain.', null, 'N', null, 'Shop'));
        $this->assertSame('Chain by Shimano, available from Shop.', ItemSeo::description(null, '', null, 'Chain', 'Shimano', 'Shop'));
        $long = ItemSeo::description(null, str_repeat('lorem ipsum ', 40), null, 'N', null, 'Shop');
        $this->assertLessThanOrEqual(155, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    public function test_markup_has_product_offer_barcodes_and_breadcrumbs(): void
    {
        $ld = ItemSeo::jsonld([
            'name' => 'Chain', 'description' => 'd', 'images' => ['https://x/a.jpg', 'https://x/b.jpg'], 'brand' => 'Shimano',
            'sku' => 'S1', 'mpn' => 'M1', 'upc' => '012345678905', 'ean' => '', 'price_cents' => 2999, 'currency' => 'usd',
            'in_stock' => true, 'url' => 'https://shop/shop/chain-x', 'shop' => 'Shop',
            'crumbs' => [['Home', 'https://shop/'], ['Shop', 'https://shop/shop'], ['Chain', 'https://shop/shop/chain-x']],
        ]);
        $p = $ld[0];
        $this->assertSame('https://schema.org', $p['@context']);
        $this->assertSame('Product', $p['@type']);
        $this->assertSame('29.99', $p['offers']['price']);
        $this->assertSame('USD', $p['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $p['offers']['availability']);
        $this->assertSame('012345678905', $p['gtin12']);
        $this->assertCount(2, $p['image']);
        $this->assertSame(3, count($ld[1]['itemListElement']));
        $this->assertSame('BreadcrumbList', $ld[1]['@type']);
    }

    public function test_no_price_means_no_offer_and_odd_barcodes_are_left_out(): void
    {
        $p = ItemSeo::jsonld(['name' => 'X', 'url' => 'u', 'shop' => 'S', 'upc' => '123', 'price_cents' => null])[0];
        $this->assertArrayNotHasKey('offers', $p);
        $this->assertArrayNotHasKey('gtin12', $p);
    }

    public function test_share_links_carry_the_source(): void
    {
        $this->assertSame('https://s/shop/a?utm_source=share&utm_medium=email', ItemSeo::shareUrl('https://s/shop/a', 'email'));
    }
}
