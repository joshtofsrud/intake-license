<?php

namespace App\Support;

use App\Models\AdminNavGroup;
use App\Models\AdminNavItem;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-NAV-ORDER — what the sidebar should look like, from the database,
 * falling back to what each class declares.
 *
 * Additive by design. This project has lost navigation items before, and a
 * stored-order table is an easy way to lose more: if the table were treated as
 * the list of what exists, every new page would be invisible until someone
 * remembered to add a row. So a class with no row keeps its declared group and
 * label and appears at the end of that group. The ONLY way an item is hidden
 * is an explicit hidden = true.
 */
class AdminNav
{
    private static ?array $itemMemo = null;
    private static ?array $groupMemo = null;

    /** Customisations by class name, or an empty array before the migration. */
    public static function items(): array
    {
        if (self::$itemMemo !== null) return self::$itemMemo;

        if (! Schema::hasTable('admin_nav_items')) {
            return self::$itemMemo = [];
        }

        return self::$itemMemo = AdminNavItem::all()->keyBy('class')->all();
    }

    public static function groups(): array
    {
        if (self::$groupMemo !== null) return self::$groupMemo;

        if (! Schema::hasTable('admin_nav_groups')) {
            return self::$groupMemo = [];
        }

        return self::$groupMemo = AdminNavGroup::all()->keyBy('name')->all();
    }

    public static function forget(): void
    {
        self::$itemMemo = null;
        self::$groupMemo = null;
    }

    /** The group this class belongs in — customised, or as declared. */
    public static function groupFor(string $class, ?string $declared): ?string
    {
        $row = self::items()[$class] ?? null;
        return ($row && $row->group) ? $row->group : $declared;
    }

    /** The label to show — customised, or as declared. */
    public static function labelFor(string $class, ?string $declared): ?string
    {
        $row = self::items()[$class] ?? null;
        return ($row && $row->label) ? $row->label : $declared;
    }

    /** Sort within the group. An unknown item sorts last, not first. */
    public static function sortFor(string $class, ?int $declared): int
    {
        $row = self::items()[$class] ?? null;
        if ($row) return $row->sort;
        return $declared ?? 9999;
    }

    /**
     * Hidden ONLY when a row says so. A missing row means visible — the
     * opposite default would make every new page invisible.
     */
    public static function isHidden(string $class): bool
    {
        $row = self::items()[$class] ?? null;
        return $row ? (bool) $row->hidden : false;
    }

    /** Group ordering for the panel, in the shape Filament expects. */
    public static function groupOrder(): array
    {
        $rows = self::groups();
        if (! $rows) return [];

        return collect($rows)
            ->sortBy('sort')
            ->map(fn ($g) => $g->label ?: $g->name)
            ->values()->all();
    }
}
