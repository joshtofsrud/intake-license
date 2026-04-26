<?php

namespace App\Filament\Resources\RoadmapEntryResource\Pages;

use App\Filament\Resources\RoadmapEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoadmapEntries extends ListRecords
{
    protected static string $resource = RoadmapEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
