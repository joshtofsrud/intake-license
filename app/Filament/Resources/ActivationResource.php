<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivationResource\Pages;
use App\Models\Activation;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ActivationResource extends Resource
{
    protected static ?string $model = Activation::class;
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup = 'Installs';
    protected static ?string $navigationLabel = 'All installs';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BadgeColumn::make('type')
                    ->colors(['gray' => 'free', 'success' => 'premium']),
                Tables\Columns\TextColumn::make('site_url')->searchable(),
                Tables\Columns\TextColumn::make('site_name')->placeholder('—'),
                Tables\Columns\TextColumn::make('license.license_key')
                    ->label('License key')
                    ->placeholder('Free install')
                    ->searchable(),
                Tables\Columns\TextColumn::make('plugin_version')->label('Plugin')->placeholder('—'),
                Tables\Columns\TextColumn::make('wp_version')->label('WP')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->label('Last seen')
                    ->sortable(),
                Tables\Columns\TextColumn::make('activated_at')
                    ->dateTime()
                    ->label('First seen')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(['free' => 'Free', 'premium' => 'Premium']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivations::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Activations are created by the API, not manually
    }
}
