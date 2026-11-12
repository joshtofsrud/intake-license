<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\Shipping\ShippingRates;
use PHPUnit\Framework\TestCase;

/** MARKER-SHIP-MODE-DEFAULT */
class ShipModeDefaultTest extends TestCase
{
    private function mode(array $settings): string
    {
        $t = (new \ReflectionClass(Tenant::class))->newInstanceWithoutConstructor();
        $settings['storefront']['ship_from_zip'] = '99201';
        $t->setRawAttributes(['settings' => json_encode($settings)]);
        return ShippingRates::config($t)['mode'];
    }

    public function test_a_shop_that_never_saved_a_mode_is_flat(): void
    {
        $this->assertSame('flat', $this->mode([]));
        $this->assertSame('flat', $this->mode(['storefront' => ['shop_on' => true]]));
    }

    public function test_saved_and_junk_modes(): void
    {
        $this->assertSame('weight', $this->mode(['storefront' => ['ship_mode' => 'weight']]));
        $this->assertSame('flat', $this->mode(['storefront' => ['ship_mode' => 'bogus']]));
    }
}
