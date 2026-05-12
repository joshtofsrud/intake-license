<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListSiteSettings — shows the single site_settings row with an Edit
 * action. Acts as the resource's index page.
 */
class ListSiteSettings extends ListRecords
{
    protected static string $resource = SiteSettingsResource::class;

    protected static ?string $title = 'Site settings';

    // No "Create" header action — site settings is singleton.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
