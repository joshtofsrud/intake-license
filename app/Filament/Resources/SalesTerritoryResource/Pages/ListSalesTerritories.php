<?php
// MARKER-SALES-FIND
namespace App\Filament\Resources\SalesTerritoryResource\Pages;

use App\Filament\Resources\SalesTerritoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesTerritories extends ListRecords
{
    protected static string $resource = SalesTerritoryResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()->label('Add territory')]; }
}
