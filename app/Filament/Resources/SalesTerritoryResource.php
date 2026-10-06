<?php
// MARKER-SALES-FIND
// MARKER-SALES-TERRITORY2

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
use Illuminate\Support\HtmlString;

class SalesTerritoryResource extends Resource
{
    use \App\Support\UsesAdminNav;
    protected static ?string $model = SalesTerritory::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Sales setup'; // MARKER-SALES-SETUP
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
            Forms\Components\Placeholder::make('legend')->hiddenLabel()->columnSpanFull()
                ->content(new HtmlString('A shop belongs to this territory when <b>any</b> of the three below matches it. '
                    . 'Saving hands every matching open prospect that has no rep yet to this territory\'s owner. '
                    . 'Prospects that already have a rep keep them.')),

            Forms\Components\Section::make('What it covers')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),
                Forms\Components\CheckboxList::make('loops')->label('Washington loops')
                    ->options(collect(SalesProspect::LOOPS)->mapWithKeys(fn ($v, $k) => [$k => "L$k  $v"])->all())
                    ->columns(3)->live()->columnSpanFull()
                    ->helperText('Shops added from Places get their loop from where they are.'),
                Forms\Components\TagsInput::make('states')
                    ->label('Whole states (2-letter)')->placeholder('ID')->separator(',')->live()->columnSpanFull()
                    ->helperText('Every shop in these states. Narrow a state with the latitude band below.'),
                Forms\Components\TextInput::make('lat_min')->label('Only north of latitude')->numeric()->step('0.000001')->live(onBlur: true)
                    ->helperText('Optional, applies to the states above. 37.0 keeps northern California.'),
                Forms\Components\TextInput::make('lat_max')->label('Only south of latitude')->numeric()->step('0.000001')->live(onBlur: true),
                Forms\Components\TextInput::make('center_label')->label('Circle around')->placeholder('City or address')->maxLength(191)->live(onBlur: true)
                    ->helperText('Optional. Looked up once when you save (one Places lookup).'),
                Forms\Components\TextInput::make('radius_miles')->label('Circle radius (miles)')->numeric()->minValue(1)->maxValue(500)->live(onBlur: true),
                Forms\Components\TextInput::make('priority')->numeric()->default(100)->live(onBlur: true)
                    ->helperText('When two territories match a shop, the lower number wins.'),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->inline(false),
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
                    ->helperText('Blank means the territory belongs to House.'),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Before you save')->schema([
                Forms\Components\Placeholder::make('preview')->hiddenLabel()
                    ->content(fn (Forms\Get $get, ?SalesTerritory $record) => self::previewText($get, $record)),
            ]),
        ]);
    }

    private static function previewText(Forms\Get $get, ?SalesTerritory $record): HtmlString
    {
        $t = $record ? clone $record : new SalesTerritory();
        $t->loops        = array_values(array_map('intval', (array) $get('loops')));
        $t->states       = array_values(array_filter(array_map(fn ($s) => strtoupper(trim((string) $s)), (array) $get('states'))));
        $t->lat_min      = $get('lat_min') !== '' && $get('lat_min') !== null ? $get('lat_min') : null;
        $t->lat_max      = $get('lat_max') !== '' && $get('lat_max') !== null ? $get('lat_max') : null;
        $t->priority     = (int) ($get('priority') ?: 100);
        $t->radius_miles = $get('radius_miles') ? (int) $get('radius_miles') : null;

        $note = '';
        $label = trim((string) $get('center_label'));
        if ($label === '' || ! $t->radius_miles) {
            $t->center_lat = null; $t->center_lng = null;
        } elseif (! $record || $label !== (string) $record->center_label || ! $record->hasCircle()) {
            $t->center_lat = null; $t->center_lng = null;
            $note = ' The circle is counted after you save, once its center is looked up.';
        }

        $p = TerritoryResolver::preview($t);
        if ($p['total'] === 0) {
            return new HtmlString('Matches no open prospects yet.' . e($note));
        }
        $bits = ["Matches <b>{$p['total']}</b> open prospects."];
        if ($p['assign'])  $bits[] = "<b>{$p['assign']}</b> have no rep and will be assigned when you save.";
        if ($p['keep'])    $bits[] = "{$p['keep']} already have a rep and keep them.";
        if ($p['overlap']) $bits[] = "{$p['overlap']} are also matched by a territory with an equal or lower priority number, which takes them.";
        return new HtmlString(implode(' ', $bits) . e($note));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->description(function () {
                if (SalesTerritory::query()->doesntExist()) return null;
                $n = TerritoryResolver::unmatchedCount();
                return $n ? "$n open prospects aren't in any territory, so they stay with House." : 'Every open prospect is in a territory.';
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('states')->label('Covers')->state(fn (SalesTerritory $r) => $r->statesLabel())->wrap(),
                Tables\Columns\TextColumn::make('owner')->state(fn (SalesTerritory $r) => $r->ownerLabel()),
                Tables\Columns\TextColumn::make('prospects_count')->label('Prospects')->counts('prospects')->sortable(),
                Tables\Columns\TextColumn::make('priority')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->emptyStateHeading('No territories yet')
            ->emptyStateDescription('Every prospect stays with House until a territory covers it.')
            ->headerActions([
                Tables\Actions\Action::make('reapply')
                    ->label('Re-run rules on unassigned prospects')->icon('heroicon-o-arrow-path')
                    ->disabled(fn () => SalesTerritory::query()->doesntExist())
                    ->tooltip(fn () => SalesTerritory::query()->doesntExist() ? 'Add a territory first' : null)
                    ->requiresConfirmation()
                    ->modalDescription('Assigns a territory, agency and rep to every open prospect that has no rep yet. Prospects that already have a rep are not touched.')
                    ->action(function () {
                        $n = TerritoryResolver::applyToUnassigned();
                        Notification::make()->title("$n prospects assigned")->success()->send();
                    }),
            ]);
    }

    /** Looks up the circle center when it changed. Returns null and notifies when it can't be found. */
    public static function geocode(array $data, ?SalesTerritory $record = null): ?array
    {
        $label = trim((string) ($data['center_label'] ?? ''));
        if ($label === '' || empty($data['radius_miles'])) {
            $data['center_label'] = $label ?: null;
            $data['center_lat'] = null; $data['center_lng'] = null;
            if ($label === '') $data['radius_miles'] = null;
        } elseif (! $record || $label !== (string) $record->center_label || ! $record->hasCircle()) {
            try {
                $loc = (new \App\Services\Sales\PlacesClient())->locate($label);
            } catch (\Throwable $e) {
                $loc = null;
            }
            if (! $loc) {
                Notification::make()->danger()->title("Couldn't find \"$label\" on the map")
                    ->body('Check the spelling or add the state, then save again. Nothing was saved.')->send();
                return null;
            }
            $data['center_lat'] = $loc['lat'];
            $data['center_lng'] = $loc['lng'];
        }

        $hasRule = ! empty($data['loops']) || ! empty($data['states']) || ! empty($data['radius_miles']);
        if (! $hasRule) {
            Notification::make()->danger()->title('Pick at least one loop, state or circle')
                ->body('A territory with none of these would match nothing. Nothing was saved.')->send();
            return null;
        }
        return $data;
    }

    public static function afterWrite(): void
    {
        $n = TerritoryResolver::applyToUnassigned();
        Notification::make()->success()->title('Territory saved')
            ->body($n ? "$n prospects assigned." : 'No unassigned prospects matched.')->send();
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
