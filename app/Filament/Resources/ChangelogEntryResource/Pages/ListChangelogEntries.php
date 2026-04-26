<?php

namespace App\Filament\Resources\ChangelogEntryResource\Pages;

use App\Filament\Resources\ChangelogEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChangelogEntries extends ListRecords
{
    protected static string $resource = ChangelogEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
