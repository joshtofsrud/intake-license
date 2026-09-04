<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AddonResource\Pages;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Support\AddonPricing;
use App\Support\PlanPricing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// MARKER-ADDON-CATALOG — the add-on catalogue: what exists, what it costs,
// who gets it free, and whether it is still offered.
class AddonResource extends Resource
{
    use \App\Support\GatedByAdminArea;
    use \App\Support\UsesAdminNav;
    protected static string $adminArea = 'tenants';

    protected static ?string $model = Addon::class;

    protected static ?string $navigationIcon  = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?string $navigationLabel = 'Add-ons';
    protected static ?int    $navigationSort  = 36;
    protected static ?string $modelLabel       = 'add-on';
    protected static ?string $pluralModelLabel = 'add-ons';
    protected static ?string $slug             = 'addons';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('What it is')
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(80),
                    Forms\Components\TextInput::make('code')->required()->maxLength(64)
                        ->helperText('Used in code and on statements. Changing it on a live add-on breaks the link to shops that have it.')
                        ->disabledOn('edit'),
                    Forms\Components\Textarea::make('description')->rows(2),
                    Forms\Components\TextInput::make('tooltip')->maxLength(160)
                        ->helperText('The one-liner shown beside it in the shop\'s catalogue.'),
                ])->columns(2),

            Forms\Components\Section::make('Price')
                ->schema([
                    Forms\Components\TextInput::make('new_price')
                        ->label('Price')
                        ->numeric()->prefix('$')
                        ->helperText('Leave alone to keep the current price. Add-ons are not in Stripe, so this figure is what actually gets billed.')
                        ->default(fn (?Addon $record) => $record ? AddonPricing::for($record->code) / 100 : null),

                    Forms\Components\DatePicker::make('new_price_from')
                        ->label('From')
                        ->default(now())
                        ->helperText('Today applies it now; a future date waits, and last month\'s statements keep the old price.'),

                    Forms\Components\Select::make('billing_cadence')
                        ->options(['monthly' => 'Every month', 'one_time' => 'One-off'])
                        ->default('monthly')->required(),

                    Forms\Components\TextInput::make('price_display_override')
                        ->label('Show instead of the price')
                        ->maxLength(40)
                        ->helperText('For anything that is not a simple number — "from $49", "by quote".'),
                ])->columns(2),

            Forms\Components\Section::make('Who gets it')
                ->schema([
                    Forms\Components\CheckboxList::make('included_in_plans')
                        ->label('Included free with')
                        ->options(fn () => collect(array_keys(PlanPricing::all()))
                            ->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all())
                        ->columns(4)
                        ->helperText('Shops on these plans get it at no charge — it still appears on their statement, at $0.'),

                    Forms\Components\Select::make('status')
                        ->options(Addon::STATUSES)
                        ->default(Addon::ACTIVE)
                        ->required()
                        ->helperText('Closed keeps existing shops working and billed; retired turns it off for them too.'),

                    Forms\Components\Toggle::make('is_self_serve')
                        ->label('A shop can turn it on themselves'),

                    Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),

                Tables\Columns\TextColumn::make('price')->label('Price')
                    ->getStateUsing(fn (Addon $r) => $r->price_display_override
                        ?: '$' . number_format(AddonPricing::for($r->code) / 100, 2)
                           . ($r->billing_cadence === 'one_time' ? ' once' : '/mo')),

                Tables\Columns\TextColumn::make('included_in_plans')->label('Free with')
                    ->getStateUsing(fn (Addon $r) => $r->included_in_plans
                        ? collect($r->included_in_plans)->map(fn ($p) => ucfirst($p))->implode(', ')
                        : '—')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('shops')->label('Shops using it')
                    ->getStateUsing(fn (Addon $r) => DB::table('tenant_feature_addons')
                        ->where('addon_code', $r->code)
                        ->whereIn('status', ['active', 'canceling', 'failed_payment'])
                        ->count()),

                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        Addon::DEPRECATED  => 'Closed to new',
                        Addon::RETIRED => 'Retired',
                        default        => 'Active',
                    })
                    ->color(fn ($state) => match ($state) {
                        Addon::DEPRECATED  => 'warning',
                        Addon::RETIRED => 'danger',
                        default        => 'success',
                    }),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAddons::route('/'),
            'edit'  => Pages\EditAddon::route('/{record}/edit'),
            'create'=> Pages\CreateAddon::route('/create'),
        ];
    }
}
