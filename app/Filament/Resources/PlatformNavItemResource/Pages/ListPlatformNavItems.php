<?php

namespace App\Filament\Resources\PlatformNavItemResource\Pages;

use App\Filament\Resources\PlatformNavItemResource;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformNavItems extends ListRecords
{
    protected static string $resource = PlatformNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New nav item')
                ->mutateFormDataUsing(function (array $data): array {
                    $platform = Tenant::where('is_platform', true)->firstOrFail();
                    $data['tenant_id'] = $platform->id;
                    return $data;
                }),
        ];
    }
}
