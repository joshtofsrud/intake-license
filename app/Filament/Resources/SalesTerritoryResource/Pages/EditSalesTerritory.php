<?php
namespace App\Filament\Resources\SalesTerritoryResource\Pages;

use App\Filament\Resources\SalesTerritoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesTerritory extends EditRecord
{
    protected static string $resource = SalesTerritoryResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $out = SalesTerritoryResource::geocode($data, $this->record);
        if ($out === null) $this->halt();
        return $out;
    }

    protected function afterSave(): void { SalesTerritoryResource::afterWrite(); }
}
