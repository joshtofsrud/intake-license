<?php

namespace App\Filament\Resources\PlatformNavItemResource\Pages;

use App\Filament\Resources\PlatformNavItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlatformNavItem extends EditRecord
{
    protected static string $resource = PlatformNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
