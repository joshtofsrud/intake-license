<?php
// MARKER-REPPANEL-RESOURCE

namespace App\Filament\Rep\Resources\RepProspectResource\Pages;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRepProspects extends ListRecords
{
    protected static string $resource = RepProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Register prospect')];
    }
}
