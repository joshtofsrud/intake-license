<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use App\Models\SiteSettings;
use Filament\Resources\Pages\EditRecord;

/**
 * EditSiteSettings — opens the singleton record directly.
 * No "list" page; the resource's "index" route points here.
 */
class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingsResource::class;

    public function mount($record = null): void
    {
        // Always load the single row.
        $row = SiteSettings::current();
        parent::mount($row->id);
    }

    protected function getRedirectUrl(): string
    {
        // Stay on the same page after save — there's no list to go back to.
        return $this->getResource()::getUrl('index');
    }
}
