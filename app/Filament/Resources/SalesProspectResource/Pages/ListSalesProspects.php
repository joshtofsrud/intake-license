<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources\SalesProspectResource\Pages;

use App\Filament\Resources\SalesProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesProspects extends ListRecords
{
    protected static string $resource = SalesProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [SalesProspectResource\Widgets\SalesFunnelWidget::class];
    }
}
