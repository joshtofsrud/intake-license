<?php
// MARKER-BILLING-DISCOUNTS
namespace App\Filament\Resources\TenantBillingDiscountResource\Pages;

use App\Filament\Resources\TenantBillingDiscountResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTenantBillingDiscount extends CreateRecord
{
    protected static string $resource = TenantBillingDiscountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::guard('web')->user()?->email;
        return $data;
    }
}
