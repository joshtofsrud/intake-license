<?php
// MARKER-BILLING-DISCOUNTS
namespace App\Filament\Resources\TenantDiscountResource\Pages;

use App\Filament\Resources\TenantDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTenantDiscounts extends ListRecords
{
    protected static string $resource = TenantDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
