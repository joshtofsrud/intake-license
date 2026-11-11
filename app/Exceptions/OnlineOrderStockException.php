<?php

namespace App\Exceptions;

/**
 * MARKER-ECOM-STOCK-GUARD: the cart holds something the shop can't hand over.
 * Thrown before any money moves; the checkout turns it into a plain-words
 * message the shopper can act on.
 */
class OnlineOrderStockException extends \RuntimeException
{
    /** @param array<string,array> $blocked lineId => result (see OrderStock::inspect) */
    public function __construct(public readonly array $blocked)
    {
        parent::__construct(self::summary($blocked));
    }

    public static function summary(array $blocked): string
    {
        return collect($blocked)->pluck('message')->filter()->implode(' ');
    }
}
