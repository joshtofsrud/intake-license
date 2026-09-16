<?php
// MARKER-SALES-FIND

namespace App\Filament\Resources;

use App\Filament\Resources\SalesTerritoryResource\Pages;
use App\Models\SalesProspect;
use App\Models\SalesTerritory;
use App\Services\Sales\TerritoryResolver;
use App\Support\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SalesTerritoryResource extends Resource
{
    use \App\Support\UsesAdminNav;
    protected static ?string $model = SalesTerritory::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 35;
    protected static ?string $navigationLabel = 'Territories';
    protected static ?string $slug            = 'sales-territories';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Rule')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),
                Forms\Components\TagsInput::make('states')
                    ->label('States (2-letter)')->placeholder('WA')->separator(',')
                    ->helperText('A shop matches when its state is in this list.')
                    ->required()->columnSpanFull(),
                Forms\Components\TextInput::make('lat_min')->label('Only north of latitude')->numeric()->step('0.000001')
                    ->helperText('Optional. 37.0 keeps northern California; leave blank for the whole state.'),
                Forms\Components\TextInput::make('lat_max')->label('Only south of latitude')->numeric()->step('0.000001'),
                Forms\Components\TextInput::make('priority')->numeric()->default(100)
                    ->helperText('When two rules match, the lower number wins.'),
                Forms\Components\Toggle::make('is_active')->default(true)->inline(false),
            ]),
            Forms\Components\Section::make('Owner')->columns(2)->schema([
                Forms\Components\Select::make('agency_id')->label('Agency')
                    ->options(fn () => \App\Models\SalesAgency::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->native(false)->live()->nullable(),
                Forms\Components\Select::make('sales_rep_id')->label('Rep')
                    ->options(fn (Forms\Get $get) => \App\Models\SalesRep::query()
                        ->when($get('agency_id'), fn ($q, $a) => $q->where('agency_id', $a))
                        ->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->native(false)->nullable()
                    ->helperText('Blank = the territory belongs to the house (Josh).'),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('states')->label('Matches')->state(fn (SalesTerritory $r) => $r->statesLabel())->wrap(),
                Tables\Columns\TextColumn::make('owner')->state(fn (SalesTerritory $r) => $r->ownerLabel()),
                Tables\Columns\TextColumn::make('prospects_count')->label('Prospects')->counts('prospects')->sortable(),
                Tables\Columns\TextColumn::make('priority')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->headerActions([
                Tables\Actions\Action::make('reapply')
                    ->label('Re-run rules on unassigned prospects')->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Assigns a territory, agency and rep to every open prospect that has no rep yet. Prospects that already have a rep are not touched.')
                    ->action(function () {
                        TerritoryResolver::forget();
                        $n = 0;
                        SalesProspect::query()->open()->whereNull('sales_rep_id')->chunkById(500, function ($rows) use (&$n) {
                            foreach ($rows as $p) if (TerritoryResolver::apply($p)) $n++;
                        });
                        Notification::make()->title("$n prospects assigned")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesTerritories::route('/'),
            'create' => Pages\CreateSalesTerritory::route('/create'),
            'edit'   => Pages\EditSalesTerritory::route('/{record}/edit'),
        ];
    }
}
