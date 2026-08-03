<?php
// MARKER-PATCH-HLC5

namespace App\Filament\Resources;

use App\Filament\Resources\DistributorFieldMapResource\Pages;
use App\Models\DistributorFieldMap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master-admin field mapping — how each distributor's feed fills the canonical
 * Intake catalog. One row per (distributor, canonical_field). Nothing about a
 * distributor's feed lives in code; the sync/resolver execute this grid.
 */
class DistributorFieldMapResource extends Resource
{
    protected static ?string $model = DistributorFieldMap::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?string $navigationLabel = 'Field Mapping';
    protected static ?int    $navigationSort  = 20;
    protected static ?string $recordTitleAttribute = 'canonical_field';

    protected static ?string $modelLabel       = 'field map';
    protected static ?string $pluralModelLabel = 'field maps';
    protected static ?string $slug             = 'distributor-field-maps';

    /** The transform vocabulary the resolver understands. */
    public const TRANSFORMS = [
        'direct'              => 'direct — copy as-is',
        'bool'                => 'bool — truthy → boolean',
        'pick_from_array'     => 'pick_from_array — select element by match',
        'lookup'              => 'lookup — value → value table',
        'coalesce'            => 'coalesce — first non-empty',
        'pick_category_level' => 'pick_category_level — choose a level',
        'join_array'          => 'join_array — join a list',
        'json_passthrough'    => 'json_passthrough — store the whole value',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mapping')
                ->description('Which canonical field, filled from which source path, transformed how.')
                ->schema([
                    // MARKER-FIELDMAP-PICKERS — every one of these lists is
                    // derivable, so none of them should be typed from memory.
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Select::make('distributor_code')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::distributorOptions())
                            ->default('HLC')
                            ->live()
                            ->helperText('Codes in use, plus any the registry supports'),

                        Forms\Components\Select::make('canonical_field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('The Intake column this fills'),

                        // TextInput with a datalist, NOT a Select: a Select can
                        // only return one of its options, and a nested path like
                        // CaseDimensions.Quantity never appears as a top-level
                        // key — nor does a coalesce row, which has no path at
                        // all. The datalist suggests the feed's real columns
                        // while still accepting anything typed.
                        Forms\Components\TextInput::make('source_path')
                            ->maxLength(255)
                            ->datalist(fn (Forms\Get $get) => array_keys(
                                self::sourcePathOptions((string) $get('distributor_code'))
                            ))
                            ->helperText('Columns seen in this feed — or type a nested path, or leave blank for coalesce.'),
                    ]),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('transform')
                            ->required()->native(false)
                            ->options(self::TRANSFORMS)
                            ->default('direct')
                            ->live(),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()->default(0),
                    ]),
                ]),

            Forms\Components\Section::make('Transform args')
                ->description('JSON arguments for the transform. Examples: pick_from_array → {"match":{"TypeId":0},"field":"Amount","cast":"cents"} · pick_category_level → {"level":1,"field":"CategoryName"} · coalesce → {"order":[{"path":"UPC"},{"path":"EAN"},{"concat":["BrandId","MFGPartNumber"],"sep":"-"}]}')
                ->collapsed(fn (Forms\Get $get) => blank($get('transform_args')))
                ->schema([
                    Forms\Components\Textarea::make('transform_args')
                        ->label('transform_args (JSON)')
                        ->rows(5)->rules(['nullable', 'json'])
                        ->formatStateUsing(fn ($state) => filled($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null)
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)
                        ->extraAttributes(['style' => 'font-family:ui-monospace,monospace;font-size:12px']),
                ]),

            Forms\Components\Section::make('Lookup table')
                ->description('Only for the lookup transform — value → value. e.g. StatusId map 7 → sellable, 9 → discontinued.')
                ->collapsed(fn (Forms\Get $get) => $get('transform') !== 'lookup' && blank($get('lookup_table')))
                ->schema([
                    Forms\Components\KeyValue::make('lookup_table')
                        ->keyLabel('source value')->valueLabel('canonical value')
                        ->reorderable(false),
                ]),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Toggle::make('is_active')->default(true),
                    Forms\Components\TextInput::make('notes')->maxLength(255),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('canonical_field')
                    ->label('Canonical field')->searchable()->sortable()
                    ->weight('bold')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('source_path')
                    ->label('Source')->fontFamily('mono')->color('info')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('transform')
                    ->badge()->color('gray'),
                Tables\Columns\TextColumn::make('args_summary')
                    ->label('Args / lookup')->fontFamily('mono')
                    ->limit(56)->tooltip(fn ($state) => $state)
                    ->state(fn (DistributorFieldMap $r) => self::argsSummary($r))
                    ->color('gray'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_active')->label('On'),
            ])
            ->defaultSort('sort_order')
            ->groups([
                Tables\Grouping\Group::make('distributor_code')->label('Distributor')->collapsible(),
            ])
            ->defaultGroup('distributor_code')
            ->filters([
                Tables\Filters\SelectFilter::make('distributor_code')
                    ->options(fn () => DistributorFieldMap::query()
                        ->distinct()->pluck('distributor_code', 'distributor_code')->all()),
                Tables\Filters\SelectFilter::make('transform')->options(self::TRANSFORMS),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->excludeAttributes(['id'])
                    ->beforeReplicaSaved(fn (DistributorFieldMap $replica) => $replica->canonical_field = $replica->canonical_field . '_copy'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function argsSummary(DistributorFieldMap $r): string
    {
        $parts = [];
        if (! empty($r->transform_args)) {
            $parts[] = json_encode($r->transform_args, JSON_UNESCAPED_SLASHES);
        }
        if (! empty($r->lookup_table)) {
            $parts[] = json_encode($r->lookup_table, JSON_UNESCAPED_SLASHES);
        }
        return $parts ? implode('  ', $parts) : '—';
    }

    /**
     * MARKER-FIELDMAP-PICKERS — codes that already have maps, plus everything
     * the registry supports, so a newly registered adapter is selectable
     * before it has a single mapping row.
     *
     * @return array<string,string>
     */
    public static function distributorOptions(): array
    {
        $used = \App\Models\DistributorFieldMap::query()
            ->select('distributor_code')->distinct()
            ->pluck('distributor_code')->all();

        $supported = [];
        try {
            $supported = app(\App\Services\Distributors\DistributorRegistry::class)->supported();
        } catch (\Throwable) {
            // Registry unavailable in some contexts; the used list still works.
        }

        $codes = collect($used)
            ->merge(array_map('strtoupper', (array) $supported))
            ->map(fn ($c) => strtoupper((string) $c))
            ->filter()->unique()->sort()->values();

        return $codes->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * The columns the resolver may write. Read from the model's fillable so it
     * cannot drift from the schema, minus the ones the sync fills itself —
     * offering those would invite a map that is silently overwritten.
     *
     * @return array<string,string>
     */
    public static function canonicalFieldOptions(): array
    {
        $owned = [
            'source_raw', 'display_name', 'display_subtitle', 'search_text',
            'distributor_name', 'last_synced_at', 'is_active',
            'prev_cost_cents', 'prev_map_cents', 'prev_msrp_cents',
            'cost_cents', // deliberately nulled at platform level; cost is per-tenant
        ];

        return collect((new \App\Models\PlatformDistributorCatalog())->getFillable())
            ->reject(fn ($f) => in_array($f, $owned, true))
            ->sort()->values()
            ->mapWithKeys(fn ($f) => [$f => $f])
            ->all();
    }

    /**
     * The feed's own column names, read from source_raw on real rows.
     *
     * This is the list that was impossible to know: the distributor's columns
     * are not written down anywhere in Intake except inside the rows they
     * produced. Sampling a handful covers optional columns that a single row
     * might omit.
     *
     * @return array<string,string>
     */
    public static function sourcePathOptions(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return [];
        }

        static $cache = [];
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $rows = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->whereNotNull('source_raw')
            ->latest('last_synced_at')
            ->limit(25)
            ->pluck('source_raw');

        $keys = [];
        foreach ($rows as $raw) {
            $arr = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($arr)) {
                continue;
            }
            foreach (array_keys($arr) as $k) {
                $keys[(string) $k] = true;
            }
        }

        ksort($keys);

        return $cache[$code] = collect(array_keys($keys))
            ->mapWithKeys(fn ($k) => [$k => $k])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDistributorFieldMaps::route('/'),
            'create' => Pages\CreateDistributorFieldMap::route('/create'),
            'edit'   => Pages\EditDistributorFieldMap::route('/{record}/edit'),
        ];
    }
}
