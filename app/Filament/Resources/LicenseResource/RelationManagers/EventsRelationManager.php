<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event_type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'activated'   => 'success',
                        'deactivated' => 'warning',
                        'suspended'   => 'danger',
                        'cancelled'   => 'danger',
                        'key_reset'   => 'warning',
                        default       => 'gray',
                    }),
                Tables\Columns\TextColumn::make('site_url')->placeholder('—'),
                Tables\Columns\TextColumn::make('plugin_version')->label('Plugin')->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->placeholder('—'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->label('When'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
