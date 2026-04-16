<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'licenses';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('license_key')
                ->required()
                ->maxLength(64)
                ->default(fn () => \App\Models\License::generateKey()),
            Forms\Components\Select::make('tier')
                ->options(['premium' => 'Premium'])
                ->required(),
            Forms\Components\Select::make('status')
                ->options([
                    'active'    => 'Active',
                    'suspended' => 'Suspended',
                    'expired'   => 'Expired',
                    'cancelled' => 'Cancelled',
                ])
                ->required(),
            Forms\Components\TextInput::make('site_limit')
                ->numeric()
                ->default(1)
                ->required(),
            Forms\Components\Toggle::make('saas_access')
                ->label('SaaS access'),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->nullable(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('license_key')->searchable(),
            Tables\Columns\BadgeColumn::make('status')
                ->colors([
                    'success' => 'active',
                    'warning' => 'suspended',
                    'danger'  => fn ($state) => in_array($state, ['expired', 'cancelled']),
                ]),
            Tables\Columns\TextColumn::make('tier'),
            Tables\Columns\TextColumn::make('activations_count')
                ->label('Sites')
                ->counts('activations'),
            Tables\Columns\TextColumn::make('site_limit')->label('Limit'),
            Tables\Columns\IconColumn::make('saas_access')->boolean(),
            Tables\Columns\TextColumn::make('expires_at')->dateTime()->placeholder('Never'),
        ])
        ->headerActions([Tables\Actions\CreateAction::make()])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }
}
