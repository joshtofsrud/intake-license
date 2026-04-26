<?php

namespace App\Filament\Resources\ChangelogEntryResource\Pages;

use App\Filament\Resources\ChangelogEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChangelogEntry extends EditRecord
{
    protected static string $resource = ChangelogEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
