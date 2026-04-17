<?php

namespace App\Filament\Resources\DebugLogResource\Pages;

use App\Filament\Resources\DebugLogResource;
use App\Filament\Widgets\DebugLogHeaderStats;
use Filament\Resources\Pages\ListRecords;

class ListDebugLogs extends ListRecords
{
    protected static string $resource = DebugLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            DebugLogHeaderStats::class,
            // DebugLogErrorsChart::class,  // temporarily disabled — investigating Livewire registration issue
        ];
    }
}
