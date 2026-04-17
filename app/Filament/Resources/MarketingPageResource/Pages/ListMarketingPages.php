<?php

namespace App\Filament\Resources\MarketingPageResource\Pages;

use App\Filament\Resources\MarketingPageResource;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListMarketingPages extends ListRecords
{
    protected static string $resource = MarketingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New page')
                ->mutateFormDataUsing(function (array $data): array {
                    // Force every new marketing page under the platform tenant.
                    $platform = Tenant::where('is_platform', true)->firstOrFail();
                    $data['tenant_id']    = $platform->id;
                    $data['is_home']      = false;
                    $data['is_published'] = $data['is_published'] ?? false;
                    $data['is_in_nav']    = $data['is_in_nav']    ?? true;

                    // Auto-slug from title if the user didn't provide one.
                    if (empty($data['slug']) && ! empty($data['title'])) {
                        $data['slug'] = Str::slug($data['title']);
                    }
                    return $data;
                }),
        ];
    }
}
