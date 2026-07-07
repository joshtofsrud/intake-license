<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\Pages;

use App\Filament\Resources\SalesAgencyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesAgencies extends ListRecords
{
    protected static string $resource = SalesAgencyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
