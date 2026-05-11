<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use App\Models\SiteSettings;
use Filament\Resources\Pages\EditRecord;

/**
 * EditSiteSettings — single-record editor for the global site_settings row.
 *
 * Uses resolveRecord() instead of overriding mount() because mount()
 * signatures vary across Filament versions and the override broke admin.
 */
class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingsResource::class;

    /**
     * Filament calls this to load the record for editing.
     * We always return the singleton row regardless of any URL parameter.
     */
    protected function resolveRecord($key): \Illuminate\Database\Eloquent\Model
    {
        return SiteSettings::current();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
