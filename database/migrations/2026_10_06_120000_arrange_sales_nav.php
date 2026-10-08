<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales menu becomes Today, Prospects; the rest moves to a
 * collapsed "Sales setup" group. The menu is stored (NavArrange), so the
 * stored rows are updated; you can still rearrange it there afterwards.
 * Stale custom labels for renamed pages (Pipeline, Route day, Campaigns) are
 * cleared so the new names show.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_nav_items') || ! Schema::hasTable('admin_nav_groups')) return;

        $items = [
            \App\Filament\Pages\SalesRouteDay::class                 => ['group' => 'Sales',       'sort' => 1, 'hidden' => false],
            \App\Filament\Pages\SalesPipeline::class                 => ['group' => 'Sales',       'sort' => 2, 'hidden' => false],
            \App\Filament\Pages\SalesFindShops::class                => ['group' => 'Sales',       'sort' => 3, 'hidden' => true],
            \App\Filament\Resources\SalesProspectResource::class     => ['group' => 'Sales',       'sort' => 4, 'hidden' => true],
            \App\Filament\Resources\SalesChannelResource::class      => ['group' => 'Sales setup', 'sort' => 1, 'hidden' => false],
            \App\Filament\Resources\SalesAgencyResource::class       => ['group' => 'Sales setup', 'sort' => 2, 'hidden' => false],
            \App\Filament\Resources\SalesTerritoryResource::class    => ['group' => 'Sales setup', 'sort' => 3, 'hidden' => false],
            \App\Filament\Pages\SalesPlacesSettings::class           => ['group' => 'Sales setup', 'sort' => 4, 'hidden' => false],
        ];
        $stale = ['pipeline', 'route day', 'campaigns'];

        foreach ($items as $class => $v) {
            $row = DB::table('admin_nav_items')->where('class', $class)->first();
            $label = $row && $row->label && ! in_array(mb_strtolower($row->label), $stale, true) ? $row->label : null;
            DB::table('admin_nav_items')->updateOrInsert(['class' => $class], $v + ['label' => $label, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
        }

        if (! DB::table('admin_nav_groups')->where('name', 'Sales setup')->exists()) {
            $sales = DB::table('admin_nav_groups')->where('name', 'Sales')->value('sort');
            if ($sales !== null) {
                DB::table('admin_nav_groups')->where('sort', '>', $sales)->increment('sort');
                DB::table('admin_nav_groups')->insert(['name' => 'Sales setup', 'label' => null, 'sort' => $sales + 1, 'collapsed' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // The menu is user-arranged in NavArrange; nothing to undo automatically.
    }
};
