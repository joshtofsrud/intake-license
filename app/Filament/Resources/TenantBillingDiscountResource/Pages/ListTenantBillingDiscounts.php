<?php
// MARKER-BILLING-DISCOUNTS
namespace App\Filament\Resources\TenantBillingDiscountResource\Pages;

use App\Filament\Resources\TenantBillingDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTenantBillingDiscounts extends ListRecords
{
    protected static string $resource = TenantBillingDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
