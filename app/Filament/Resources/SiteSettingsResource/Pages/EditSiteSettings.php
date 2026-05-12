<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditSiteSettings — stock EditRecord. The singleton row (id=1) is created
 * by the resource's getEloquentQuery() before edits land on this page.
 * No overrides — keeps the page Filament-version-safe.
 */
class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
