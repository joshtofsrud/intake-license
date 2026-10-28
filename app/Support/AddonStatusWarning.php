<?php

namespace App\Support;

use App\Models\Addon;

/**
 * MARKER-ADDON-SAFETY: what a status change does to shops, in words, for the
 * yes/no before it's saved (Pricing page panel and the add-on edit page).
 * Null when the change takes nothing away (anything back to Active).
 */
class AddonStatusWarning
{
    public static function for(Addon $a, string $next): ?string
    {
        if ($next === $a->status) return null;
        $n = $a->shopsWithItCount();
        $shops = $n === 1 ? '1 shop has it' : $n . ' shops have it';

        return match ($next) {
            Addon::RETIRED => "Retire {$a->name}?\nIt switches off straight away for every shop that has it, including shops paying for it, and leaves the pricing page. Right now {$shops}.",
            Addon::DEPRECATED => "Close {$a->name} to new shops?\nThe shops that have it keep it and are still billed; nobody new can add it. Right now {$shops}.",
            default => null,
        };
    }
}
