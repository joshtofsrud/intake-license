<?php

namespace App\Filament\Resources\RoadmapEntryResource\Pages;

use App\Filament\Resources\RoadmapEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoadmapEntry extends EditRecord
{
    protected static string $resource = RoadmapEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
