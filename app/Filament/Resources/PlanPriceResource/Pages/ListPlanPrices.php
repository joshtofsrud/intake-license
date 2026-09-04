<?php
// MARKER-PLAN-PRICING
namespace App\Filament\Resources\PlanPriceResource\Pages;

use App\Filament\Resources\PlanPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlanPrices extends ListRecords
{
    protected static string $resource = PlanPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New price')];
    }
}
