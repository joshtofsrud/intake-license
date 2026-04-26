<?php

namespace App\Filament\Resources\ChangelogEntryResource\Pages;

use App\Filament\Resources\ChangelogEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChangelogEntry extends CreateRecord
{
    protected static string $resource = ChangelogEntryResource::class;
}
