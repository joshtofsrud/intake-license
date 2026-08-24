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
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'catalog';

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
        // MARKER-PICK-ATTR — these three were in the resolver but not here,
        // so rows using them couldn't be edited through this form.
        'pick_attribute'      => 'pick_attribute — first matching attribute by name',
        'zip_pipe'            => 'zip_pipe — two pipe strings → {Name,Value} pairs',
        'split_pipe'          => 'split_pipe — pipe string → list',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mapping')
                ->description('Which canonical field, filled from which source path, transformed how.')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Select::make('distributor_code')
                            ->label('Distributor')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::distributorOptions())
                            ->default('HLC')
                            ->live(),
                        Forms\Components\Select::make('canonical_field')
                            ->label('Intake field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('What this becomes inside Intake'),
                        // MARKER-FIELDMAP-PICKERS2 — a real dropdown. Nested
                        // paths are flattened into the options, and rows with
                        // no path (coalesce, computed) pick "none" explicitly
                        // instead of being left to guess at an empty box.
                        Forms\Components\Select::make('source_path')
                            ->label('Feed column')
                            ->native(false)->searchable()
                            ->options(fn (Forms\Get $get) => self::sourcePathOptions((string) $get('distributor_code')))
                            ->helperText('Columns seen in this distributor\'s feed'),
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

            // MARKER-FIELDMAP-PROBE — what does this feed actually send?
            Forms\Components\Section::make('See real values from this feed')
                ->description('Enter any product identifier to list every column this distributor sends for it, with its value and whether it is mapped yet.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('probe_identifier')
                        ->label('UPC, EAN or part number')
                        ->dehydrated(false)   // display only — not a column
                        ->live(onBlur: true)
                        ->placeholder('e.g. 4717784045276')
                        ->helperText('Leave the form and come back to keep editing; this box never saves.'),

                    Forms\Components\Placeholder::make('probe_output')
                        ->hiddenLabel()
                        ->content(fn (Forms\Get $get) => new \Illuminate\Support\HtmlString(
                            self::probeHtml(
                                (string) $get('distributor_code'),
                                (string) $get('probe_identifier')
                            )
                        )),
                ]),

            // MARKER-TRANSFORM-LEGEND — was one dense run-on line naming four
            // of the eleven transforms. The legend below replaces it; keeping
            // both would guarantee they drift.
            Forms\Components\Section::make('What the transforms do')
                ->description('Every transform the resolver understands. The one you have selected is highlighted.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('transform_legend')
                        ->hiddenLabel()
                        ->content(fn (Forms\Get $get) => new \Illuminate\Support\HtmlString(
                            self::legendHtml((string) $get('transform'))
                        )),
                ]),

            Forms\Components\Section::make('Transform args')
                ->description('JSON arguments for the selected transform — see the legend above for the shape each one expects.')
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
     * MARKER-FIELDMAP-PICKERS2 — codes that already have maps, plus every code
     * the registry supports, so a newly registered adapter is selectable
     * before it has a single mapping row.
     *
     * @return array<string,string>
     */
    public static function distributorOptions(): array
    {
        $used = \App\Models\DistributorFieldMap::query()
            ->select('distributor_code')->distinct()->pluck('distributor_code')->all();

        $supported = [];
        try { $supported = app(\App\Services\Distributors\DistributorRegistry::class)->supported(); }
        catch (\Throwable) {}

        return collect($used)->merge(array_map('strtoupper', (array) $supported))
            ->map(fn ($c) => strtoupper((string) $c))->filter()->unique()->sort()->values()
            ->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * MARKER-FIELDMAP-PICKERS2 — the Intake fields a map may fill, labelled in
     * plain language. The list is read from the model's fillable so it cannot
     * drift from the schema; the labels are ours, because the column name told
     * you what it is called and nothing about what it does.
     *
     * @return array<string,string>
     */
    public static function canonicalFieldOptions(): array
    {
        // Filled by the sync itself — offering them invites a map that is
        // silently overwritten on the next run.
        $owned = [
            'source_raw', 'display_name', 'display_subtitle', 'search_text',
            'distributor_name', 'last_synced_at', 'is_active',
            'prev_cost_cents', 'prev_map_cents', 'prev_msrp_cents',
            'cost_cents',
        ];

        $labels = [
            'name' => 'Product name', 'manufacturer' => 'Brand',
            'manufacturer_sku' => 'Manufacturer part number',
            'brand_id' => 'Brand id (distributor own)',
            'upc' => 'UPC barcode', 'ean' => 'EAN barcode',
            'product_key' => 'Grouping key (dedupe identity)',
            'distributor_product_no' => 'Distributor product number',
            'distributor_variant_no' => 'Distributor variant number',
            'description' => 'Description', 'category' => 'Category',
            'category_id' => 'Category id',
            'category_path' => 'Category path (Tires > Mountain)',
            'attributes' => 'Attributes (name/value pairs)',
            'images' => 'Images', 'image_urls' => 'Image URLs',
            'msrp_cents' => 'MSRP', 'map_cents' => 'MAP (minimum advertised price)',
            'alt_prices' => 'Other prices', 'uom' => 'Unit of measure',
            'case_quantity' => 'Case quantity', 'weight' => 'Weight',
            'dimensions' => 'Dimensions', 'item_group' => 'Item group',
            'size_id' => 'Size', 'color_id' => 'Colour', 'config' => 'Configuration',
            'taxable' => 'Taxable', 'is_sellable' => 'Sellable',
            'canonical_status' => 'Status',
            'source_status_id' => 'Status code (distributor own)',
            'source_status_label' => 'Status label (distributor own)',
            'source_modified_at' => 'Last changed at source',
            'ground_only' => 'Ground shipping only', 'hazmat_type' => 'Hazmat type',
            'freight_class' => 'Freight class',
            'dropship_fulfillable' => 'Dropship available',
        ];

        return collect((new \App\Models\PlatformDistributorCatalog())->getFillable())
            ->reject(fn ($f) => in_array($f, $owned, true))
            ->sort()->values()
            ->mapWithKeys(fn ($f) => [$f => ($labels[$f] ?? $f) . '  (' . $f . ')'])
            ->all();
    }

    /**
     * MARKER-FIELDMAP-PICKERS2 — the feed's own columns, as a real list.
     *
     * A distributor's column names are written down nowhere in Intake except
     * inside the rows they produced, so this reads source_raw — the untouched
     * feed row kept on every catalog row — and walks it.
     *
     * Nested objects are flattened to dotted paths (CaseDimensions.Quantity)
     * so they appear as options rather than forcing a typed guess. Lists are
     * NOT walked: a map targets `Prices` as a whole and lets a transform pick
     * from it, so Prices.0.Amount would be a misleading thing to offer.
     *
     * @return array<string,string>
     */
    public static function sourcePathOptions(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['' => '- none (coalesce or computed) -'];
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

        $paths = [];

        $walk = function (array $arr, string $prefix, int $depth) use (&$walk, &$paths): void {
            if ($depth > 3) { return; }
            foreach ($arr as $k => $v) {
                if (is_int($k)) { continue; }
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                $paths[$path] = true;
                if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                    $walk($v, $path, $depth + 1);
                }
            }
        };

        foreach ($rows as $raw) {
            $arr = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($arr)) { $walk($arr, '', 1); }
        }

        ksort($paths);

        $out = ['' => '- none (coalesce or computed) -'];
        foreach (array_keys($paths) as $path) { $out[$path] = $path; }

        return $cache[$code] = $out;
    }

    /**
     * MARKER-FIELDMAP-PROBE — every column this distributor sends for one
     * product, with its value and its current mapping.
     *
     * Reads source_raw, the untouched feed row stored on every catalog row,
     * so this is what the distributor actually sent rather than what we made
     * of it. Nested objects are flattened to the same dotted paths the Feed
     * column picker offers, so what you see here is what you can select.
     */
    /**
     * MARKER-TRANSFORM-LEGEND — the reference table.
     *
     * Examples are lifted from the seeded maps rather than invented, so a
     * reader can find the same row in the list and see it working.
     */
    public static function legendHtml(string $selected): string
    {
        $rows = [
            ['direct', 'Copy the value at source_path, unchanged.', '—',
             'name ← item_description'],

            ['bool', 'Coerce a truthy value to a real boolean.', '—',
             'taxable ← Taxable'],

            ['lookup', 'Translate one value into another through a table.', 'the lookup table below',
             'status 7 → sellable, 9 → discontinued'],

            ['coalesce', 'First non-empty of several paths; can concatenate.', '{"order":[…]} — paths tried in order',
             'product_key ← UPC, else EAN, else BrandId-MFGPartNumber'],

            ['pick_from_array', 'Choose one element of a list by matching a field.', '{"match":{…},"field":"…"}',
             'cost_cents ← the Prices entry where TypeId = 0'],

            ['pick_category_level', 'Take one level out of a category tree.', '{"level":1,"field":"CategoryName"}',
             'category ← the most specific level'],

            ['join_array', 'Flatten a list into one string.', '{"sep":", "}',
             'joins a list of values for display'],

            ['json_passthrough', 'Store the whole structure as-is.', '—',
             'attributes ← Attributes (lossless)'],

            ['pick_attribute', 'First attribute whose name matches, by priority.',
             '{"names":["Color","Colour"]} · add {"keys":…,"values":…,"sep":"|"} when the source is two parallel pipe strings instead of {Name,Value} pairs',
             'color ← Color, else Colour, else Primary Color'],

            ['zip_pipe', 'Two parallel pipe strings into {Name,Value} pairs.',
             '{"keys":"…","values":"…","sep":"|"}',
             'Model|Color|Size + Snapback Hat|Gray|One Size'],

            ['split_pipe', 'One pipe string into a list.', '{"sep":"|","prefix":"…"} — prefix makes relative paths fetchable',
             'images ← image_paths'],
        ];

        $out = '<div style="font-size:12px;line-height:1.5">'
             . '<table style="width:100%;border-collapse:collapse">'
             . '<thead><tr>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Transform</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">What it does</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Args</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Example</th>'
             . '</tr></thead><tbody>';

        foreach ($rows as [$name, $what, $args, $example]) {
            $on = ($name === $selected);
            $bg = $on ? 'background:rgba(190,242,100,.10);' : '';
            $out .= '<tr style="border-top:1px solid rgba(127,127,127,.18);' . $bg . '">'
                 . '<td style="padding:7px 8px;vertical-align:top;white-space:nowrap">'
                 . '<code style="font-size:11.5px;font-weight:' . ($on ? '700' : '500') . '">' . e($name) . '</code>'
                 . ($on ? ' <span style="font-size:10px;opacity:.7">selected</span>' : '')
                 . '</td>'
                 . '<td style="padding:7px 8px;vertical-align:top">' . e($what) . '</td>'
                 . '<td style="padding:7px 8px;vertical-align:top;opacity:.75"><code style="font-size:11px">' . e($args) . '</code></td>'
                 . '<td style="padding:7px 8px;vertical-align:top;opacity:.75">' . e($example) . '</td>'
                 . '</tr>';
        }

        $out .= '</tbody></table>'
             . '<div style="margin-top:10px;opacity:.7">'
             . 'cast is available on any transform: <code>{"cast":"cents"}</code>, '
             . '<code>"trim"</code>, <code>"string"</code>, <code>"cents_zero_null"</code> (0.0 becomes null, for MAP).'
             . '</div></div>';

        return $out;
    }

    public static function probeHtml(string $code, string $identifier): string
    {
        $code = strtoupper(trim($code));
        $identifier = trim($identifier);

        if ($code === '' || $identifier === '') {
            return '<div style="opacity:.6;font-size:13px">Pick a distributor and enter an identifier.</div>';
        }

        $row = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->where(function ($q) use ($identifier) {
                $q->where('upc', $identifier)
                  ->orWhere('ean', $identifier)
                  ->orWhere('manufacturer_sku', $identifier)
                  ->orWhere('distributor_variant_no', $identifier)
                  ->orWhere('distributor_product_no', $identifier);
            })
            ->first();

        if (! $row) {
            return '<div style="opacity:.7;font-size:13px">Nothing in the '
                 . e($code) . ' catalog matches <code>' . e($identifier)
                 . '</code>. Identifiers are matched exactly.</div>';
        }

        $raw = is_string($row->source_raw) ? json_decode($row->source_raw, true) : $row->source_raw;
        if (! is_array($raw) || $raw === []) {
            return '<div style="opacity:.7;font-size:13px">This row has no stored feed data. '
                 . 'It predates source_raw being kept — re-sync the catalog to populate it.</div>';
        }

        // Flatten exactly like the Feed column picker, so every path shown
        // here is one you can actually select.
        $flat = [];
        $walk = function (array $arr, string $prefix, int $depth) use (&$walk, &$flat): void {
            if ($depth > 3) { return; }
            foreach ($arr as $k => $v) {
                if (is_int($k)) { continue; }
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                    $walk($v, $path, $depth + 1);
                } else {
                    $flat[$path] = $v;
                }
            }
        };
        $walk($raw, '', 1);
        ksort($flat);

        // Which Intake field each column currently feeds. This is what turns
        // the list from "here is the data" into "here is what is left to do".
        $mapped = \App\Models\DistributorFieldMap::query()
            ->where('distributor_code', $code)
            ->where('is_active', true)
            ->get(['canonical_field', 'source_path', 'transform', 'transform_args']);

        $byPath = [];
        foreach ($mapped as $m) {
            if (filled($m->source_path)) {
                $byPath[(string) $m->source_path][] = (string) $m->canonical_field;
            }
            // coalesce / zip_pipe reference their columns inside the args
            foreach ((array) ($m->transform_args ?? []) as $v) {
                foreach ((array) (is_array($v) ? $v : [$v]) as $inner) {
                    if (is_string($inner) && isset($flat[$inner])) {
                        $byPath[$inner][] = (string) $m->canonical_field . ' (via ' . $m->transform . ')';
                    }
                }
            }
        }

        $h  = '<div style="font-size:12.5px;margin-bottom:8px;opacity:.75">'
            . e($row->name ?: (string) $row->distributor_variant_no)
            . ' &middot; ' . count($flat) . ' columns in this feed row</div>';

        $h .= '<div style="max-height:460px;overflow:auto;border:1px solid rgba(255,255,255,.1);border-radius:8px">';
        $h .= '<table style="width:100%;border-collapse:collapse;font-size:12.5px">';
        $h .= '<thead><tr style="text-align:left;opacity:.6;font-size:10.5px;letter-spacing:.05em;text-transform:uppercase">'
            . '<th style="padding:7px 10px">Feed column</th>'
            . '<th style="padding:7px 10px">Value</th>'
            . '<th style="padding:7px 10px">Mapped to</th></tr></thead><tbody>';

        foreach ($flat as $path => $value) {
            if (is_bool($value)) {
                $shown = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $shown = json_encode($value, JSON_UNESCAPED_SLASHES);
            } elseif ($value === null || $value === '') {
                $shown = '';
            } else {
                $shown = (string) $value;
            }
            $blank = ($shown === '');
            $shown = $blank ? 'empty' : \Illuminate\Support\Str::limit($shown, 160);

            $to = $byPath[$path] ?? [];
            $toHtml = $to
                ? '<span style="color:#4ade80">' . e(implode(', ', array_unique($to))) . '</span>'
                : '<span style="opacity:.4">not mapped</span>';

            $h .= '<tr style="border-top:1px solid rgba(255,255,255,.07)">'
                . '<td style="padding:7px 10px;font-family:ui-monospace,monospace">' . e($path) . '</td>'
                . '<td style="padding:7px 10px' . ($blank ? ';opacity:.35;font-style:italic' : '') . '">' . e($shown) . '</td>'
                . '<td style="padding:7px 10px">' . $toHtml . '</td>'
                . '</tr>';
        }

        return $h . '</tbody></table></div>';
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
