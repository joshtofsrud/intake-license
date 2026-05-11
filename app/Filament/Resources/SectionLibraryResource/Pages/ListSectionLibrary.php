<?php

namespace App\Filament\Resources\SectionLibraryResource\Pages;

use App\Filament\Resources\SectionLibraryResource;
use Filament\Resources\Pages\ListRecords;

class ListSectionLibrary extends ListRecords
{
    protected static string $resource = SectionLibraryResource::class;

    protected static ?string $title = 'Section library';
}
