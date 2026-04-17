<?php

namespace App\Filament\Resources\MarketingPageResource\Pages;

use App\Filament\Resources\MarketingPageResource;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateMarketingPage extends CreateRecord
{
    protected static string $resource = MarketingPageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $platform = Tenant::where('is_platform', true)->firstOrFail();
        $data['tenant_id']    = $platform->id;
        $data['is_home']      = false;
        $data['is_published'] = $data['is_published'] ?? false;

        if (empty($data['slug']) && ! empty($data['title'])) {
            $data['slug'] = Str::slug($data['title']);
        }
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Jump straight into the content editor after creating a page —
        // the settings the form captured are only metadata; the real work
        // is adding sections, which happens in the editor.
        return route('admin.marketing-pages.edit-content', $this->record->id);
    }
}
