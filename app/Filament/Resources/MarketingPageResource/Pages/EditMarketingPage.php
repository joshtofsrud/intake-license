<?php

namespace App\Filament\Resources\MarketingPageResource\Pages;

use App\Filament\Resources\MarketingPageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketingPage extends EditRecord
{
    protected static string $resource = MarketingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit_content')
                ->label('Edit content')
                ->icon('heroicon-o-paint-brush')
                ->color('primary')
                ->url(fn () => route('admin.marketing-pages.edit-content', $this->record->id)),

            Actions\DeleteAction::make()
                ->visible(fn () => ! $this->record->is_home && ! str_starts_with($this->record->slug, '__')),
        ];
    }
}
