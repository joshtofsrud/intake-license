<?php
// MARKER-ADDON-CATALOG
namespace App\Filament\Resources\AddonResource\Pages;

use App\Filament\Resources\AddonResource;
use App\Models\AddonPrice;
use App\Support\AddonPricing;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditAddon extends EditRecord
{
    protected static string $resource = AddonResource::class;

    protected ?array $priceRow = null;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()
            ->label('Delete')
            ->requiresConfirmation()
            ->modalDescription('Deleting removes it from the catalogue entirely. If shops are using it, close or retire it instead — that keeps their records intact.')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $cents = isset($data['new_price']) ? (int) round(((float) $data['new_price']) * 100) : null;
        $from  = $data['new_price_from'] ?? now()->toDateString();
        unset($data['new_price'], $data['new_price_from']);

        // Only write a row when the figure actually changed, or the table fills
        // with a duplicate every time someone saves the page.
        if ($cents !== null && ($cents !== AddonPricing::for($this->record->code)
            || ! \Carbon\Carbon::parse($from)->isToday())) {
            $this->priceRow = ['cents' => $cents, 'from' => $from];
            $data['price_cents'] = $cents;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->priceRow) {
            AddonPrice::updateOrCreate(
                ['addon_code' => $this->record->code, 'effective_from' => $this->priceRow['from']],
                ['price_cents' => $this->priceRow['cents'], 'created_by' => Auth::guard('web')->user()?->email],
            );

            Notification::make()->success()
                ->title('Price saved')
                ->body(\Carbon\Carbon::parse($this->priceRow['from'])->isFuture()
                    ? 'It takes effect on ' . \Carbon\Carbon::parse($this->priceRow['from'])->format('M j, Y') . '.'
                    : 'It applies from today. Past statements keep the price they were built with.')
                ->send();
        }

        AddonPricing::forget();
    }
}
