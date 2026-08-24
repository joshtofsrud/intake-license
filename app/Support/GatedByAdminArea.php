<?php
// MARKER-ADMIN-NAV-GATE — shared gate for Filament pages (canAccess hides nav
// AND 403s the route) and resources (canViewAny does the same). Each class
// declares protected static string $adminArea; access follows the matrix.
// 'view' and 'full' both grant entry — write-level distinctions live in the
// pages themselves.

namespace App\Support;

use Illuminate\Support\Facades\Auth;

trait GatedByAdminArea
{
    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), static::$adminArea);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }
}
