<?php

namespace App\Filament\Resources\TenantDomainResource\Pages;

use App\Filament\Resources\TenantDomainResource;
use App\Models\Tenant;
use App\Services\DomainProvisioningService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Custom create page. Instead of letting Filament insert a row directly,
 * we route through DomainProvisioningService::createForTenant which also
 * registers the hostname with Cloudflare. If CF fails, we never persist
 * a half-baked local row.
 */
class CreateTenantDomain extends CreateRecord
{
    protected static string $resource = TenantDomainResource::class;

    /**
     * Override handleRecordCreation: route through the provisioning
     * service so Cloudflare registration happens atomically with the
     * DB insert.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $tenant = Tenant::findOrFail($data['tenant_id']);

        try {
            $domain = app(DomainProvisioningService::class)->createForTenant(
                $tenant,
                $data['hostname'],
                [
                    'is_primary' => (bool) ($data['is_primary'] ?? false),
                    'role'       => $data['role'] ?? 'both',
                    'alias_mode' => $data['alias_mode'] ?? 'redirect',
                ],
            );
            return $domain;
        } catch (\App\Exceptions\CloudflareException $e) {
            Notification::make()
                ->title('Cloudflare error')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
