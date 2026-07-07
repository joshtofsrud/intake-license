<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources\SalesProspectResource\Pages;

use App\Filament\Resources\SalesProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesProspect extends EditRecord
{
    protected static string $resource = SalesProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
