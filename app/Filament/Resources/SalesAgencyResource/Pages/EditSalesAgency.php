<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\Pages;

use App\Filament\Resources\SalesAgencyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesAgency extends EditRecord
{
    protected static string $resource = SalesAgencyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
