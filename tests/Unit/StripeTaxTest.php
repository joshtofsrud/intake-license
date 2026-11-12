<?php

namespace Tests\Unit;

use App\Services\Tenant\StripeTax;
use PHPUnit\Framework\TestCase;

class StripeTaxTest extends TestCase
{
    public function test_build_skips_free_lines_and_adds_shipping(): void
    {
        $p = StripeTax::build([
            ['ref' => 'a|shop', 'amount' => 5000, 'code' => ''],
            ['ref' => 'b|shop', 'amount' => 0, 'code' => StripeTax::GOODS],
            ['ref' => 'c|sup', 'amount' => 1200, 'code' => StripeTax::NONTAXABLE],
        ], 899, ['zip' => '97401', 'state' => 'OR', 'line1' => '1 Main', 'city' => 'Eugene']);
        $this->assertCount(2, $p['line_items']);
        $this->assertSame(StripeTax::GOODS, $p['line_items'][0]['tax_code']);
        $this->assertSame('exclusive', $p['line_items'][0]['tax_behavior']);
        $this->assertSame(899, $p['shipping_cost']['amount']);
        $this->assertSame('97401', $p['customer_details']['address']['postal_code']);
        $this->assertSame('shipping', $p['customer_details']['address_source']);
        $this->assertArrayNotHasKey('shipping_cost', StripeTax::build([['ref' => 'a', 'amount' => 100, 'code' => '']], 0, ['zip' => '97401']));
    }

    public function test_parse_per_line_and_shipping(): void
    {
        $r = StripeTax::parse([
            'id' => 'taxcalc_1',
            'tax_amount_exclusive' => 415,
            'line_items' => ['data' => [['reference' => 'a|shop', 'amount_tax' => 300], ['reference' => 'c|sup', 'amount_tax' => 70]]],
            'shipping_cost' => ['amount_tax' => 45],
            'tax_breakdown' => [['tax_rate_details' => ['display_name' => 'Sales tax', 'percentage_decimal' => '7.5']]],
        ]);
        $this->assertSame('taxcalc_1', $r['calc_id']);
        $this->assertSame(415, $r['tax_cents']);
        $this->assertSame(['a|shop' => 300, 'c|sup' => 70], $r['lines']);
        $this->assertSame(45, $r['shipping_tax']);
        $this->assertSame(['Sales tax 7.5%'], $r['jurisdictions']);
    }

    public function test_parse_with_no_tax_registered(): void
    {
        $r = StripeTax::parse(['id' => 'taxcalc_2', 'tax_amount_exclusive' => 0, 'line_items' => ['data' => [['reference' => 'a', 'amount_tax' => 0]]]]);
        $this->assertSame(0, $r['tax_cents']);
        $this->assertSame(0, $r['shipping_tax']);
    }
}
