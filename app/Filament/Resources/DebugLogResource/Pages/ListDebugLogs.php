<?php

namespace App\Filament\Resources\DebugLogResource\Pages;

use App\Filament\Resources\DebugLogResource;
use App\Filament\Widgets\DebugLogHeaderStats;
use App\Models\DebugLog;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListDebugLogs extends ListRecords
{
    protected static string $resource = DebugLogResource::class;

    /**
     * MARKER-ERROR-PARITY — ?activeTab=errors is linked from the dashboard,
     * Open issues, the platform inbox and every JobFailureReporter email.
     * Without tabs the param was ignored and the link opened the whole log.
     */
    public function getTabs(): array
    {
        return [
            'all'    => Tab::make('All'),
            'errors' => Tab::make('Open issues')
                ->modifyQueryUsing(fn ($query) => $query->issues()->where('is_resolved', false))
                ->badge(fn () => DebugLog::issues()->where('is_resolved', false)->count() ?: null)
                ->badgeColor('danger'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DebugLogHeaderStats::class,
        ];
    }
}
