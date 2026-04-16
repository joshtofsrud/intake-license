<?php

namespace App\Filament\Resources\LicenseResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ActivationsRelationManager extends RelationManager
{
    protected static string $relationship = 'activations';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site_url')->searchable(),
                Tables\Columns\TextColumn::make('site_name')->placeholder('—'),
                Tables\Columns\TextColumn::make('plugin_version')->label('Plugin')->placeholder('—'),
                Tables\Columns\TextColumn::make('wp_version')->label('WP')->placeholder('—'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->label('Last seen'),
                Tables\Columns\TextColumn::make('activated_at')->dateTime()->label('Activated'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('Deactivate'),
            ]);
    }
}
