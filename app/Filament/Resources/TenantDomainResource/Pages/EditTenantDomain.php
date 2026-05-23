<?php

namespace App\Filament\Resources\TenantDomainResource\Pages;

use App\Filament\Resources\TenantDomainResource;
use App\Services\DomainProvisioningService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantDomain extends EditRecord
{
    protected static string $resource = TenantDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Remove')
                ->modalHeading('Remove custom domain')
                ->modalDescription('This deletes the domain from Cloudflare AND this database.')
                ->using(function ($record) {
                    app(DomainProvisioningService::class)->remove($record);
                }),
        ];
    }
}
