<?php
// MARKER-ADDON-CATALOG
namespace App\Filament\Resources\AddonResource\Pages;

use App\Filament\Resources\AddonResource;
use App\Models\AddonPrice;
use App\Support\AddonPricing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAddon extends CreateRecord
{
    protected static string $resource = AddonResource::class;

    protected array $priceRow = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The price is its own dated row, not a column edit.
        $this->priceRow = [
            'cents' => isset($data['new_price']) ? (int) round(((float) $data['new_price']) * 100) : 0,
            'from'  => $data['new_price_from'] ?? now()->toDateString(),
        ];
        unset($data['new_price'], $data['new_price_from']);

        $data['price_cents'] = $this->priceRow['cents'];   // kept in step for the fallback
        return $data;
    }

    protected function afterCreate(): void
    {
        AddonPrice::updateOrCreate(
            ['addon_code' => $this->record->code, 'effective_from' => $this->priceRow['from']],
            ['price_cents' => $this->priceRow['cents'], 'created_by' => Auth::guard('web')->user()?->email],
        );
        AddonPricing::forget();
    }
}
