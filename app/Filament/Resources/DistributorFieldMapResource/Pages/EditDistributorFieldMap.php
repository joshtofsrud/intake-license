<?php
// MARKER-PATCH-HLC5

namespace App\Filament\Resources\DistributorFieldMapResource\Pages;

use App\Filament\Resources\DistributorFieldMapResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDistributorFieldMap extends EditRecord
{
    protected static string $resource = DistributorFieldMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
