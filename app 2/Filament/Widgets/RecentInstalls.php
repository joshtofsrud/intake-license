<?php

namespace App\Filament\Widgets;

use App\Models\Activation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentInstalls extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Recently active installs';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Activation::query()
                    ->with('license.customer')
                    ->orderByDesc('last_seen_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\BadgeColumn::make('type')
                    ->colors(['gray' => 'free', 'success' => 'premium']),
                Tables\Columns\TextColumn::make('site_url'),
                Tables\Columns\TextColumn::make('site_name')->placeholder('—'),
                Tables\Columns\TextColumn::make('license.customer.email')
                    ->label('Customer')
                    ->placeholder('Free install'),
                Tables\Columns\TextColumn::make('plugin_version')->label('Plugin'),
                Tables\Columns\TextColumn::make('wp_version')->label('WP'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->label('Last seen'),
            ]);
    }
}
