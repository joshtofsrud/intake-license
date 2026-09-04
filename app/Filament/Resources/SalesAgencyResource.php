<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources;

use App\Filament\Resources\SalesAgencyResource\Pages;
use App\Filament\Resources\SalesAgencyResource\RelationManagers;
use App\Models\SalesAgency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesAgencyResource extends Resource
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'reps';

    protected static ?string $model = SalesAgency::class;

    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 30;
    protected static ?string $navigationLabel = 'Reps & agencies';
    protected static ?string $modelLabel      = 'agency';
    protected static ?string $slug            = 'sales/agencies';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Agency')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120),
                Forms\Components\Select::make('status')
                    ->options(SalesAgency::STATUSES)->default('onboarding')
                    ->native(false)->required(),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Commission terms')->columns(3)
                ->description('Rates apply to collected revenue only. The ledger (next build) accrues against these.')
                ->schema([
                    Forms\Components\TextInput::make('commission_year1')
                        ->label('Year 1 rate')->numeric()->step('0.0001')
                        ->minValue(0)->maxValue(1)->default(0.25)
                        ->helperText('0.25 = 25% while the account is under 12 months old'),
                    Forms\Components\TextInput::make('commission_residual')
                        ->label('Residual rate')->numeric()->step('0.0001')
                        ->minValue(0)->maxValue(1)->default(0.10)
                        ->helperText('0.10 = 10% from month 13 onward'),
                    Forms\Components\Toggle::make('deal_registration')
                        ->label('Deal registration')->default(true)->inline(false)
                        ->helperText('Prospect attribution is exclusive to this agency'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount([
                    'reps',
                    'prospects',
                    'prospects as tenants_count' => fn (Builder $builder) => $builder->whereNotNull('tenant_id'),
                ])
                ->withSum(['commissionEntries as unpaid_commission_cents' => fn (Builder $builder) => $builder->where('status', 'accrued')], 'commission_cents'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->sortable()->weight('semibold')
                    ->description(fn (SalesAgency $r) => number_format($r->commission_year1 * 100, 0) . '% yr-1 → ' . number_format($r->commission_residual * 100, 0) . '% residual'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesAgency::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active'     => 'success',
                        'onboarding' => 'warning',
                        default      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('reps_count')
                    ->label('Reps')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->label('Prospects')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('tenants_count')
                    ->label('Tenants')->alignEnd()->sortable()
                    ->color('success')->weight('semibold'),
                Tables\Columns\TextColumn::make('unpaid_commission_cents')
                    ->label('Unpaid comm')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => '$' . number_format(((int) $state) / 100, 2))
                    ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'gray'),
                Tables\Columns\IconColumn::make('deal_registration')
                    ->label('Deal reg')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesAgency::STATUSES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RepsRelationManager::class,
            RelationManagers\CommissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesAgencies::route('/'),
            'create' => Pages\CreateSalesAgency::route('/create'),
            'edit'   => Pages\EditSalesAgency::route('/{record}/edit'),
        ];
    }
}
