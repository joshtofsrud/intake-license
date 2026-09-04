<?php
// MARKER-PLAN-PRICING
namespace App\Filament\Resources\PlanPriceResource\Pages;

use App\Filament\Resources\PlanPriceResource;
use App\Support\PlanPricing;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlanPrice extends EditRecord
{
    protected static string $resource = PlanPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        PlanPricing::forget();
    }
}
