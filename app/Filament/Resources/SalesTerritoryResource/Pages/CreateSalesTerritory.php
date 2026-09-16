<?php
// MARKER-SALES-FIND
namespace App\Filament\Resources\SalesTerritoryResource\Pages;

use App\Filament\Resources\SalesTerritoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesTerritory extends CreateRecord
{
    protected static string $resource = SalesTerritoryResource::class;
    protected function afterCreate(): void { \App\Services\Sales\TerritoryResolver::forget(); }
}
