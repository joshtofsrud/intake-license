<?php
// MARKER-BILLING-DISCOUNTS
namespace App\Filament\Resources\TenantDiscountResource\Pages;

use App\Filament\Resources\TenantDiscountResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTenantDiscount extends CreateRecord
{
    protected static string $resource = TenantDiscountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::guard('web')->user()?->email;
        return $data;
    }
}
