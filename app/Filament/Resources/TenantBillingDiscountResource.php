<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantBillingDiscountResource\Pages;
use App\Models\Tenant;
use App\Models\TenantBillingDiscount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// MARKER-BILLING-DISCOUNTS
class TenantBillingDiscountResource extends Resource
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static ?string $model = TenantBillingDiscount::class;

    protected static ?string $navigationIcon  = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?string $navigationLabel = 'Billing discounts';
    protected static ?int    $navigationSort  = 20;
    protected static ?string $modelLabel       = 'billing discount';
    protected static ?string $pluralModelLabel = 'billing discounts';
    protected static ?string $slug             = 'billing-discounts';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Who and why')
                ->schema([
                    Forms\Components\Select::make('tenant_id')
                        ->label('Shop')
                        ->options(fn () => Tenant::where('is_platform', false)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required(),

                    Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->helperText('This appears on the shop\'s own statement. Write it as you would say it to them.')
                        ->maxLength(160)->required(),
                ])->columns(2),

            Forms\Components\Section::make('What it takes off')
                ->schema([
                    Forms\Components\Select::make('scope')
                        ->label('Applies to')
                        ->options(TenantBillingDiscount::SCOPES)
                        ->default('both')->required()
                        ->helperText('Email and texts are never discounted — they are pass-through costs. To waive usage, write off a charge instead.'),

                    Forms\Components\TextInput::make('percent')
                        ->label('Percent off')->numeric()->minValue(1)->maxValue(100)->suffix('%')
                        ->helperText('Leave blank if using a fixed amount.'),

                    Forms\Components\TextInput::make('amount_cents')
                        ->label('Or a fixed amount')->numeric()->prefix('$')
                        ->helperText('In dollars. Leave blank if using a percentage.')
                        ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : null)
                        ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (int) round($state * 100) : null),
                ])->columns(3),

            Forms\Components\Section::make('When')
                ->schema([
                    Forms\Components\DatePicker::make('starts_on')->required()->default(now()->startOfMonth()),
                    Forms\Components\DatePicker::make('ends_on')
                        ->helperText('Leave blank for no end date.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')->label('Shop')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('reason')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('scope')->badge()
                    ->formatStateUsing(fn ($state) => TenantBillingDiscount::SCOPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')->label('Amount')
                    ->getStateUsing(fn (TenantBillingDiscount $r) => $r->describeAmount()),
                Tables\Columns\TextColumn::make('window')->label('Runs')
                    ->getStateUsing(fn (TenantBillingDiscount $r) => $r->describeWindow()),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->getStateUsing(function (TenantBillingDiscount $r) {
                        if ($r->starts_on && now()->lt($r->starts_on)) return 'Queued';
                        return $r->activeOn(now()) ? 'Active' : 'Ended';
                    })
                    ->color(fn ($state) => match ($state) {
                        'Active' => 'success', 'Queued' => 'warning', default => 'gray',
                    }),
            ])
            ->defaultSort('starts_on', 'desc')
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenantBillingDiscounts::route('/'),
            'create' => Pages\CreateTenantBillingDiscount::route('/create'),
            'edit'   => Pages\EditTenantBillingDiscount::route('/{record}/edit'),
        ];
    }
}
