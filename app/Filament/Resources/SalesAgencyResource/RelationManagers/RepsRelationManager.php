<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\RelationManagers;

use App\Models\SalesRep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RepsRelationManager extends RelationManager
{
    protected static string $relationship = 'reps';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\Select::make('role')
                ->options(SalesRep::ROLES)->default('rep')->native(false)->required(),
            Forms\Components\TextInput::make('email')->email()->maxLength(191),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')->native(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesRep::ROLES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'principal' ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('email')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->counts('prospects')->label('Prospects')->alignEnd(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
