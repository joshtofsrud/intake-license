<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseResource\Pages;
use App\Models\License;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LicenseResource extends Resource
{
    protected static ?string $model = License::class;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Licensing';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('License')->schema([
                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'email')
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('license_key')
                    ->required()
                    ->maxLength(64)
                    ->default(fn () => License::generateKey())
                    ->unique(ignoreRecord: true),
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
            ])->columns(2),

            Forms\Components\Section::make('Limits & access')->schema([
                Forms\Components\TextInput::make('site_limit')
                    ->numeric()
                    ->default(1)
                    ->required(),
                Forms\Components\Toggle::make('saas_access')
                    ->label('SaaS platform access'),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Expires at')
                    ->nullable()
                    ->helperText('Leave blank for perpetual / externally managed.'),
                Forms\Components\KeyValue::make('feature_flags')
                    ->label('Feature flag overrides')
                    ->nullable()
                    ->helperText('JSON overrides — only set if different from tier defaults.'),
            ])->columns(2),

            Forms\Components\Section::make('Notes')->schema([
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('license_key')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'suspended',
                        'danger'  => fn ($state) => in_array($state, ['expired', 'cancelled']),
                    ]),
                Tables\Columns\TextColumn::make('tier'),
                Tables\Columns\TextColumn::make('activations_count')
                    ->label('Sites')
                    ->counts('activations')
                    ->sortable(),
                Tables\Columns\TextColumn::make('site_limit')
                    ->label('Limit'),
                Tables\Columns\IconColumn::make('saas_access')
                    ->boolean()
                    ->label('SaaS'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'    => 'Active',
                        'suspended' => 'Suspended',
                        'expired'   => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('tier')
                    ->options(['premium' => 'Premium']),
                Tables\Filters\Filter::make('saas_access')
                    ->query(fn ($query) => $query->where('saas_access', true))
                    ->label('SaaS access only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('regenerate_key')
                    ->label('Regenerate key')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (License $record) {
                        $record->update(['license_key' => License::generateKey()]);
                        \App\Models\LicenseEvent::log($record, 'key_reset', [
                            'note' => 'Key regenerated via admin panel.',
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\LicenseResource\RelationManagers\ActivationsRelationManager::class,
            \App\Filament\Resources\LicenseResource\RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLicenses::route('/'),
            'create' => Pages\CreateLicense::route('/create'),
            'edit'   => Pages\EditLicense::route('/{record}/edit'),
        ];
    }
}
