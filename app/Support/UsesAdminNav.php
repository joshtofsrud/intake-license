<?php

namespace App\Support;

/**
 * MARKER-NAV-ORDER — lets a page or resource take its group, label, order and
 * visibility from the database, keeping whatever it declares as the fallback.
 *
 * Applied by overriding Filament's own accessors rather than by rewriting the
 * static properties, so a class that has never been customised behaves exactly
 * as it did before this existed.
 */
trait UsesAdminNav
{
    public static function getNavigationGroup(): ?string
    {
        return AdminNav::groupFor(static::class, static::$navigationGroup ?? null);
    }

    public static function getNavigationLabel(): string
    {
        $declared = static::$navigationLabel
            ?? static::$title
            ?? str(class_basename(static::class))->headline()->toString();

        return AdminNav::labelFor(static::class, $declared) ?? $declared;
    }

    public static function getNavigationSort(): ?int
    {
        return AdminNav::sortFor(static::class, static::$navigationSort ?? null);
    }

    public static function shouldRegisterNavigation(): bool
    {
        // A hidden item keeps its route and still works if linked or
        // bookmarked: hiding is tidying, not access control.
        if (AdminNav::isHidden(static::class)) {
            return false;
        }

        return static::$shouldRegisterNavigation ?? true;
    }
}
