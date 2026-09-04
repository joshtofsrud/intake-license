<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanPriceResource\Pages;
use App\Models\PlanPrice;
use App\Support\PlanPricing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// MARKER-PLAN-PRICING — plan prices, and prices scheduled ahead.
class PlanPriceResource extends Resource
{
    use \App\Support\GatedByAdminArea;
    use \App\Support\UsesAdminNav;
    protected static string $adminArea = 'tenants';

    protected static ?string $model = PlanPrice::class;

    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?string $navigationLabel = 'Plan prices';
    protected static ?int    $navigationSort  = 35;
    protected static ?string $modelLabel       = 'plan price';
    protected static ?string $pluralModelLabel = 'plan prices';
    protected static ?string $slug             = 'plan-prices';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('tier')
                ->label('Plan')
                ->options(fn () => collect(array_keys(PlanPricing::all()))
                    ->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all())
                ->required(),

            Forms\Components\TextInput::make('price_cents')
                ->label('Price per month')
                ->numeric()->prefix('$')->required()
                ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                ->helperText('Per licensed location. A shop with two locations pays twice this.'),

            Forms\Components\DatePicker::make('effective_from')
                ->label('Takes effect from')
                ->default(now())
                ->required()
                ->helperText('Today applies it immediately. A future date sits scheduled and takes over on its own — the old price stays current until then.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tier')->label('Plan')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))->sortable(),

                Tables\Columns\TextColumn::make('price_cents')->label('Price')
                    ->getStateUsing(fn (PlanPrice $r) => $r->dollars())->sortable(),

                Tables\Columns\TextColumn::make('effective_from')->label('From')->date('M j, Y')->sortable(),

                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->getStateUsing(function (PlanPrice $r) {
                        if ($r->isScheduled()) return 'Scheduled';
                        return PlanPricing::for($r->tier) === $r->price_cents
                            && ! PlanPrice::where('tier', $r->tier)
                                ->whereDate('effective_from', '<=', now())
                                ->where('effective_from', '>', $r->effective_from)->exists()
                            ? 'Current' : 'Superseded';
                    })
                    ->color(fn ($state) => match ($state) {
                        'Current'   => 'success',
                        'Scheduled' => 'warning',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_by')->label('Set by')->toggleable()->color('gray'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlanPrices::route('/'),
            'create' => Pages\CreatePlanPrice::route('/create'),
            'edit'   => Pages\EditPlanPrice::route('/{record}/edit'),
        ];
    }
}
