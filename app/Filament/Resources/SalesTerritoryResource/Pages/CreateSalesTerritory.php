<?php
// MARKER-SALES-FIND
// MARKER-SALES-TERRITORY2
namespace App\Filament\Resources\SalesTerritoryResource\Pages;

use App\Filament\Resources\SalesTerritoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesTerritory extends CreateRecord
{
    protected static string $resource = SalesTerritoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $out = SalesTerritoryResource::geocode($data);
        if ($out === null) $this->halt();
        return $out;
    }

    protected function afterCreate(): void { SalesTerritoryResource::afterWrite(); }
}
