<?php
// MARKER-PLAN-PRICING
namespace App\Filament\Resources\PlanPriceResource\Pages;

use App\Filament\Resources\PlanPriceResource;
use App\Support\PlanPricing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePlanPrice extends CreateRecord
{
    protected static string $resource = PlanPriceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::guard('web')->user()?->email;
        return $data;
    }

    protected function afterCreate(): void
    {
        PlanPricing::forget();
    }
}
