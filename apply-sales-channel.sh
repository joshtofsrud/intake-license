#!/usr/bin/env bash
# apply-sales-channel.sh — Sales channel base package.
# Writes: migrations (sales_prospects, sales_activities, places fields),
# models, SalesProspectResource + pages + relation manager + funnel widget,
# ImportProspects command, and the 213-shop WA seeder. Registers the resource
# in AdminPanelProvider (MARKER-SALES-REGISTER guard).
#
# Run from the repo root:  bash apply-sales-channel.sh
# Idempotent: guarded on MARKER-SALES-CORE in app/Models/SalesProspect.php.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root (artisan not found)."; exit 1; }
if [ -f app/Models/SalesProspect.php ] && grep -q MARKER-SALES-CORE app/Models/SalesProspect.php; then
  echo "apply-sales-channel.sh: already applied — skipping."; exit 0
fi

echo "Applying sales channel package ..."

mkdir -p app/Console/Commands
cat > app/Console/Commands/ImportProspects.php <<'SALESPKG_EOF_1'
<?php
// MARKER-SALES-IMPORT — Import the Google Places pipeline output into sales_prospects.
//
//   php artisan intake:import-prospects output/intake_bike_shop_prospects_master.csv
//   php artisan intake:import-prospects master.csv --operational-only --dry-run
//
// Idempotent and progress-safe:
//  - Identity is google_place_id. If a row's place id already exists, we ENRICH it.
//  - If there's no place id match, we fall back to (shop, city, state) so the
//    national run merges into your hand-built WA rows instead of duplicating them
//    (and backfills their place id / phone / website on the way through).
//  - On an existing row we only refresh DISCOVERY columns (status, rating, geo,
//    maps url, address, place id). Stage, next action, tenant link, verified flag,
//    priority, lead score, and your notes are human-owned and never overwritten.

namespace App\Console\Commands;

use App\Models\SalesProspect;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportProspects extends Command
{
    protected $signature = 'intake:import-prospects
        {path : Path to the deduped master CSV from the pipeline}
        {--operational-only : Skip shops Google marks CLOSED_TEMPORARILY / CLOSED_PERMANENTLY}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Import / enrich sales prospects from the Google Places pipeline master CSV';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("Could not open: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            $this->error('Empty CSV.');
            fclose($fh);
            return self::FAILURE;
        }
        $idx = array_flip(array_map(fn ($h) => trim((string) $h), $header));

        $get = function (array $row, string $col) use ($idx) {
            return isset($idx[$col]) ? trim((string) ($row[$idx[$col]] ?? '')) : '';
        };

        $dry        = (bool) $this->option('dry-run');
        $opOnly     = (bool) $this->option('operational-only');
        $inserted   = $enriched = $unchanged = $skippedClosed = $skippedBlank = $total = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $total++;

            $shop  = $get($row, 'shop_name');
            $city  = $get($row, 'market') ?: $get($row, 'search_city');
            $state = $get($row, 'state');
            if ($shop === '') { $skippedBlank++; continue; }

            $status = $get($row, 'business_status');
            if ($opOnly && $status !== '' && $status !== 'OPERATIONAL') {
                $skippedClosed++;
                continue;
            }

            $placeId = $get($row, 'google_place_id');

            // Discovery payload — Google's current truth about the shop.
            $discovery = array_filter([
                'business_status' => $status ?: null,
                'rating'          => $get($row, 'rating') !== '' ? (float) $get($row, 'rating') : null,
                'rating_count'    => $get($row, 'rating_count') !== '' ? (int) $get($row, 'rating_count') : null,
                'lat'             => $get($row, 'latitude') !== '' ? (float) $get($row, 'latitude') : null,
                'lng'             => $get($row, 'longitude') !== '' ? (float) $get($row, 'longitude') : null,
                'google_maps_url' => $get($row, 'google_maps_url') ?: null,
                'primary_type'    => $get($row, 'primary_type') ?: null,
                'address'         => $get($row, 'address') ?: null,
                'google_place_id' => $placeId ?: null,
                'state'           => $state ?: null,
                'route_loop'      => $get($row, 'route_loop') ?: null,
            ], fn ($v) => $v !== null);

            // These are backfill-only on an existing row (fill if empty, never clobber).
            $backfillOnlyCols = ['google_place_id', 'address', 'state', 'route_loop'];

            // Match: place id first, then (shop, city) to fold into hand-built rows.
            // We deliberately don't require state equality here, because the original
            // WA rows were seeded before the state column existed (state is NULL on
            // them) — matching on shop+city lets the national run enrich them and
            // backfill their state/place id instead of creating duplicates.
            $existing = null;
            if ($placeId !== '') {
                $existing = SalesProspect::where('google_place_id', $placeId)->first();
            }
            if (! $existing) {
                $existing = SalesProspect::query()
                    ->whereRaw('LOWER(shop) = ?', [Str::lower($shop)])
                    ->whereRaw('LOWER(COALESCE(city, "")) = ?', [Str::lower($city)])
                    ->first();
            }

            if ($existing) {
                $changes = [];
                foreach ($discovery as $col => $val) {
                    if (in_array($col, $backfillOnlyCols, true) && filled($existing->{$col})) {
                        continue;
                    }
                    if ((string) $existing->{$col} !== (string) $val) {
                        $changes[$col] = $val;
                    }
                }
                // Backfill empty contact fields without ever overwriting typed ones.
                foreach (['phone' => $get($row, 'phone'), 'website' => $get($row, 'website')] as $col => $val) {
                    if ($val !== '' && blank($existing->{$col})) {
                        $changes[$col] = $val;
                    }
                }

                if ($changes) {
                    if (! $dry) { $existing->update($changes); }
                    $enriched++;
                } else {
                    $unchanged++;
                }
                continue;
            }

            // New prospect.
            if (! $dry) {
                SalesProspect::create(array_merge($discovery, [
                    'id'          => (string) Str::uuid(),
                    'shop'        => $shop,
                    'city'        => $city ?: null,
                    'state'       => $state ?: null,
                    'route_loop'  => $get($row, 'route_loop') ?: null,
                    'priority'    => in_array($get($row, 'priority'), ['A','B','C','D'], true) ? $get($row, 'priority') : 'B',
                    'lead_score'  => $get($row, 'score') !== '' ? (int) $get($row, 'score') : 0,
                    'verified'    => false,                       // Places discovery != human verification
                    'phone'       => $get($row, 'phone') ?: null,
                    'website'     => $get($row, 'website') ?: null,
                    'best_ask'    => '15-min owner/service-manager demo',
                    'source'      => $get($row, 'source_primary') ?: 'Google Places',
                    'source_url'  => $get($row, 'source_url') ?: null,
                    'notes'       => trim('Imported via Google Places. ' . $get($row, 'score_notes')),
                ]));
            }
            $inserted++;
        }
        fclose($fh);

        $this->newLine();
        $this->table(
            ['Inserted', 'Enriched', 'Unchanged', 'Skipped (closed)', 'Skipped (blank)', 'Rows read'],
            [[$inserted, $enriched, $unchanged, $skippedClosed, $skippedBlank, $total]],
        );
        $this->info($dry
            ? 'Dry run — nothing written. Re-run without --dry-run to apply.'
            : 'Import complete.');

        return self::SUCCESS;
    }
}
SALESPKG_EOF_1
echo "  wrote app/Console/Commands/ImportProspects.php"

mkdir -p app/Filament/Resources
cat > app/Filament/Resources/SalesProspectResource.php <<'SALESPKG_EOF_2'
<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources;

use App\Filament\Resources\SalesProspectResource\Pages;
use App\Filament\Resources\SalesProspectResource\RelationManagers\ActivitiesRelationManager;
use App\Models\SalesProspect;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales channel — the Washington bike-shop territory, run from the master admin.
 * Replaces the prospecting spreadsheet. The pipeline lives in the same DB as
 * Tenants, so a prospect can be linked to a real tenant on signup.
 */
class SalesProspectResource extends Resource
{
    protected static ?string $model = SalesProspect::class;

    protected static ?string $navigationIcon  = 'heroicon-o-flag';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Prospects';
    protected static ?int    $navigationSort  = 2;
    protected static ?string $recordTitleAttribute = 'shop';
    protected static ?string $modelLabel       = 'prospect';
    protected static ?string $pluralModelLabel = 'prospects';
    protected static ?string $slug             = 'sales/prospects';

    public static function getNavigationBadge(): ?string
    {
        // Open prospects with a next action due today or earlier.
        $due = SalesProspect::open()
            ->whereNotNull('next_action_on')
            ->whereDate('next_action_on', '<=', now())
            ->count();

        return $due ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Shop')->columns(2)->schema([
                Forms\Components\TextInput::make('shop')->required()->maxLength(191)->columnSpanFull(),
                Forms\Components\TextInput::make('city')->maxLength(120),
                Forms\Components\TextInput::make('state')->maxLength(64)->placeholder('WA / OR / ID…'),
                Forms\Components\TextInput::make('region')->maxLength(120),
                Forms\Components\Select::make('loop')
                    ->options(SalesProspect::LOOPS)
                    ->native(false)
                    ->placeholder('— trip loop —'),
                Forms\Components\TextInput::make('route_loop')->maxLength(120)
                    ->placeholder('National route loop (e.g. PNW / Oregon Loop)'),
                Forms\Components\TextInput::make('type')->maxLength(120)
                    ->placeholder('Independent / Service / E-bike specialist…'),
            ]),

            Forms\Components\Section::make('Qualification & stage')->columns(3)->schema([
                Forms\Components\Select::make('priority')
                    ->options(SalesProspect::PRIORITIES)->default('B')->native(false)->required(),
                Forms\Components\Toggle::make('verified')
                    ->helperText('Confirmed active shop + decision-maker.'),
                Forms\Components\TextInput::make('lead_score')->numeric()->minValue(0)->maxValue(110)->default(0),

                Forms\Components\Select::make('stage')
                    ->options(SalesProspect::STAGES)->default('prospect')->native(false)->required()->live(),
                Forms\Components\DatePicker::make('next_action_on')->label('Next action due')->native(false),
                Forms\Components\TextInput::make('next_action')->label('Next action')->maxLength(191),

                Forms\Components\Select::make('tenant_id')
                    ->label('Linked tenant')
                    ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))
                    ->searchable()->native(false)
                    ->placeholder('— not a tenant yet —')
                    ->helperText('Set this once they sign up. Pulls real billing into the funnel.')
                    ->columnSpan(2),
                Forms\Components\Textarea::make('lost_reason')->rows(2)
                    ->visible(fn (Forms\Get $get) => $get('stage') === 'lost'),
            ]),

            Forms\Components\Section::make('Contact')->columns(2)->collapsed()->schema([
                Forms\Components\TextInput::make('owner_contact')->label('Owner / contact')->maxLength(191),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
                Forms\Components\TextInput::make('email')->email()->maxLength(191),
                Forms\Components\TextInput::make('website')->url()->maxLength(255),
                Forms\Components\TextInput::make('address')->maxLength(255)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Google Places data')->columns(3)->collapsed()
                ->description('Synced from the discovery pipeline. Refreshed on each import; safe to leave as-is.')
                ->schema([
                    Forms\Components\Select::make('business_status')
                        ->options(SalesProspect::BUSINESS_STATUSES)->native(false)->placeholder('— unknown —'),
                    Forms\Components\TextInput::make('rating')->numeric()->step(0.1)->minValue(0)->maxValue(5),
                    Forms\Components\TextInput::make('rating_count')->numeric()->label('# ratings'),
                    Forms\Components\TextInput::make('primary_type')->maxLength(64),
                    Forms\Components\TextInput::make('google_place_id')->label('Place ID')->maxLength(191)->columnSpan(2),
                    Forms\Components\TextInput::make('google_maps_url')->label('Maps URL')->url()->maxLength(512)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Context')->columns(2)->collapsed()->schema([
                Forms\Components\TextInput::make('best_ask')->label('Best first ask')->maxLength(191)->columnSpanFull(),
                Forms\Components\TextInput::make('source')->maxLength(120),
                Forms\Components\TextInput::make('source_url')->url()->maxLength(512),
                Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
                Forms\Components\TextInput::make('lat')->numeric(),
                Forms\Components\TextInput::make('lng')->numeric(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shop')
                    ->searchable()->sortable()->weight('semibold')->limit(40)
                    ->description(fn (SalesProspect $r) => trim(($r->city ?? '') . ($r->state ? ", {$r->state}" : ''), ', ') ?: null),

                Tables\Columns\TextColumn::make('state')
                    ->badge()->color('gray')->sortable()->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('loop')
                    ->label('Loop')->badge()
                    ->formatStateUsing(fn ($state) => $state ? "L{$state}" : '—')
                    ->color('gray')->sortable()
                    ->tooltip(fn (SalesProspect $r) => $r->loopLabel()),

                Tables\Columns\TextColumn::make('type')->limit(22)->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')
                    ->badge()->sortable()
                    ->color(fn ($state) => match ($state) {
                        'A' => 'violet', 'B' => 'info', 'C' => 'warning', default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('verified')
                    ->boolean()->sortable(),

                Tables\Columns\TextColumn::make('business_status')
                    ->label('Status')->badge()->toggleable()
                    ->formatStateUsing(fn ($state) => SalesProspect::BUSINESS_STATUSES[$state] ?? ($state ?: '—'))
                    ->color(fn ($state) => match ($state) {
                        'OPERATIONAL' => 'success',
                        'CLOSED_TEMPORARILY' => 'warning',
                        'CLOSED_PERMANENTLY' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('rating')
                    ->label('★')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state, SalesProspect $r) => $state ? "{$state} ({$r->rating_count})" : '—'),

                Tables\Columns\TextColumn::make('lead_score')
                    ->label('Score')->sortable()->alignEnd()
                    ->color(fn ($state) => $state >= 100 ? 'success' : ($state >= 70 ? 'info' : 'gray')),

                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesProspect::STAGES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'won'         => 'success',
                        'trial'       => 'success',
                        'demo_done',
                        'demo_booked' => 'violet',
                        'contacted'   => 'info',
                        'verifying'   => 'warning',
                        'lost'        => 'danger',
                        default       => 'gray',
                    })->sortable(),

                Tables\Columns\IconColumn::make('tenant_id')
                    ->label('Tenant')
                    ->icon(fn ($state) => $state ? 'heroicon-s-check-badge' : 'heroicon-o-minus')
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->tooltip(fn (SalesProspect $r) => $r->tenant?->name),

                Tables\Columns\TextColumn::make('next_action_on')
                    ->label('Due')->date('M j')->sortable()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'gray')
                    ->placeholder('—'),
            ])
            ->defaultSort('lead_score', 'desc')
            ->groups([
                Tables\Grouping\Group::make('stage')
                    ->getTitleFromRecordUsing(fn (SalesProspect $r) => $r->stageLabel())->collapsible(),
                Tables\Grouping\Group::make('loop')
                    ->getTitleFromRecordUsing(fn (SalesProspect $r) => $r->loop ? "Loop {$r->loop} · {$r->loopLabel()}" : 'No loop')
                    ->collapsible(),
                Tables\Grouping\Group::make('region')->collapsible(),
                Tables\Grouping\Group::make('priority')->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage')->options(SalesProspect::STAGES),
                Tables\Filters\SelectFilter::make('priority')->options(SalesProspect::PRIORITIES),
                Tables\Filters\SelectFilter::make('loop')->options(SalesProspect::LOOPS),
                Tables\Filters\SelectFilter::make('state')
                    ->options(fn () => SalesProspect::query()
                        ->whereNotNull('state')->distinct()->orderBy('state')
                        ->pluck('state', 'state')->all())
                    ->multiple(),
                Tables\Filters\SelectFilter::make('business_status')
                    ->label('Business status')->options(SalesProspect::BUSINESS_STATUSES),
                Tables\Filters\Filter::make('operational')
                    ->label('Hide closed shops')
                    ->query(fn (Builder $q) => $q->operational())
                    ->toggle(),
                Tables\Filters\TernaryFilter::make('verified'),
                Tables\Filters\TernaryFilter::make('tenant_id')
                    ->label('Converted to tenant')
                    ->nullable()
                    ->trueLabel('Linked tenants')
                    ->falseLabel('Not yet tenants'),
                Tables\Filters\Filter::make('due')
                    ->label('Action due')
                    ->query(fn (Builder $q) => $q->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('log')
                    ->label('Log')->icon('heroicon-o-plus-circle')->color('gray')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->options(['note' => 'Note', 'email' => 'Email', 'call' => 'Call', 'demo' => 'Demo', 'follow_up' => 'Follow-up'])
                            ->default('call')->native(false)->required(),
                        Forms\Components\Textarea::make('body')->rows(3)->label('What happened'),
                        Forms\Components\DatePicker::make('next_action_on')->label('Next follow-up')->native(false),
                        Forms\Components\TextInput::make('next_action')->label('Next action'),
                    ])
                    ->action(function (SalesProspect $record, array $data) {
                        $record->activities()->create([
                            'type' => $data['type'],
                            'body' => $data['body'] ?? null,
                        ]);
                        $record->update([
                            'last_contacted_at' => now(),
                            'next_action_on'    => $data['next_action_on'] ?? $record->next_action_on,
                            'next_action'       => $data['next_action'] ?? $record->next_action,
                        ]);
                        Notification::make()->title('Logged')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('stage')
                    ->label('Set stage')->icon('heroicon-o-arrow-right-circle')
                    ->form([Forms\Components\Select::make('stage')->options(SalesProspect::STAGES)->required()->native(false)])
                    ->action(fn ($records, array $data) => $records->each(fn ($r) => $r->advanceTo($data['stage'])))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('verify')
                    ->label('Mark verified')->icon('heroicon-o-check-circle')->color('success')
                    ->action(fn ($records) => $records->each->update(['verified' => true]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesProspects::route('/'),
            'create' => Pages\CreateSalesProspect::route('/create'),
            'edit'   => Pages\EditSalesProspect::route('/{record}/edit'),
        ];
    }
}
SALESPKG_EOF_2
echo "  wrote app/Filament/Resources/SalesProspectResource.php"

mkdir -p app/Filament/Resources/SalesProspectResource/Pages
cat > app/Filament/Resources/SalesProspectResource/Pages/CreateSalesProspect.php <<'SALESPKG_EOF_3'
<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources\SalesProspectResource\Pages;

use App\Filament\Resources\SalesProspectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesProspect extends CreateRecord
{
    protected static string $resource = SalesProspectResource::class;
}
SALESPKG_EOF_3
echo "  wrote app/Filament/Resources/SalesProspectResource/Pages/CreateSalesProspect.php"

mkdir -p app/Filament/Resources/SalesProspectResource/Pages
cat > app/Filament/Resources/SalesProspectResource/Pages/EditSalesProspect.php <<'SALESPKG_EOF_4'
<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources\SalesProspectResource\Pages;

use App\Filament\Resources\SalesProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesProspect extends EditRecord
{
    protected static string $resource = SalesProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
SALESPKG_EOF_4
echo "  wrote app/Filament/Resources/SalesProspectResource/Pages/EditSalesProspect.php"

mkdir -p app/Filament/Resources/SalesProspectResource/Pages
cat > app/Filament/Resources/SalesProspectResource/Pages/ListSalesProspects.php <<'SALESPKG_EOF_5'
<?php
// MARKER-SALES-CORE

namespace App\Filament\Resources\SalesProspectResource\Pages;

use App\Filament\Resources\SalesProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesProspects extends ListRecords
{
    protected static string $resource = SalesProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [SalesProspectResource\Widgets\SalesFunnelWidget::class];
    }
}
SALESPKG_EOF_5
echo "  wrote app/Filament/Resources/SalesProspectResource/Pages/ListSalesProspects.php"

mkdir -p app/Filament/Resources/SalesProspectResource/RelationManagers
cat > app/Filament/Resources/SalesProspectResource/RelationManagers/ActivitiesRelationManager.php <<'SALESPKG_EOF_6'
<?php
// MARKER-SALES-ACTIVITY

namespace App\Filament\Resources\SalesProspectResource\RelationManagers;

use App\Models\SalesActivity;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';
    protected static ?string $title = 'Activity';
    protected static ?string $icon = 'heroicon-o-clock';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->options(SalesActivity::TYPES)->default('note')->native(false)->required(),
            Forms\Components\Textarea::make('body')->rows(3),
            Forms\Components\DateTimePicker::make('occurred_at')->default(now())->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesActivity::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'stage_change' => 'violet',
                        'demo'         => 'success',
                        'call', 'email' => 'info',
                        'follow_up'    => 'warning',
                        default        => 'gray',
                    }),
                Tables\Columns\TextColumn::make('body')->limit(80)->wrap()
                    ->description(fn (SalesActivity $r) => $r->stage_from && $r->stage_to
                        ? ((SalesActivity::TYPES[$r->stage_from] ?? $r->stage_from) . ' → ' . (SalesActivity::TYPES[$r->stage_to] ?? $r->stage_to))
                        : null),
                Tables\Columns\TextColumn::make('occurred_at')->dateTime('M j, g:ia')->sortable(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Log activity')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
SALESPKG_EOF_6
echo "  wrote app/Filament/Resources/SalesProspectResource/RelationManagers/ActivitiesRelationManager.php"

mkdir -p app/Filament/Resources/SalesProspectResource/Widgets
cat > app/Filament/Resources/SalesProspectResource/Widgets/SalesFunnelWidget.php <<'SALESPKG_EOF_7'
<?php
// MARKER-SALES-WIDGET

namespace App\Filament\Resources\SalesProspectResource\Widgets;

use App\Models\SalesProspect;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header widget on the prospect list. Cumulative funnel + targetable value.
 * "Targetable value" weights A/B prospects by lead_score against the cheapest
 * paid plan, so it's a deliberately conservative pipeline estimate — replace
 * the proxy with real linked-tenant MRR as conversions land.
 */
class SalesFunnelWidget extends BaseWidget
{
    protected static ?int $sort = 0;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total    = SalesProspect::count();
        $aCount   = SalesProspect::where('priority', 'A')->count();
        $verified = SalesProspect::where('verified', true)->count();
        $trials   = SalesProspect::where('stage', 'trial')->count();
        $won      = SalesProspect::where('stage', 'won')->count();
        $tenants  = SalesProspect::whereNotNull('tenant_id')->count();

        // Conservative targetable value: A/B shops, lead_score as a 0..1 weight,
        // times the lowest configured paid plan price (cents -> dollars).
        $plans = config('intake.plan_prices', []);
        $floor = $plans ? min(array_filter($plans)) / 100 : 89;
        $value = (int) round(
            SalesProspect::whereIn('priority', ['A', 'B'])
                ->get(['lead_score'])
                ->sum(fn ($p) => ($p->lead_score / 110) * $floor)
        );

        return [
            Stat::make('Prospects', number_format($total))
                ->description($aCount . ' A-priority')
                ->color('gray'),

            Stat::make('Verified', number_format($verified))
                ->description(($total - $verified) . ' to check')
                ->color($verified ? 'success' : 'gray'),

            Stat::make('Active trials', number_format($trials))
                ->description($won . ' won')
                ->color($trials ? 'warning' : 'gray'),

            Stat::make('Linked tenants', number_format($tenants))
                ->description('converted to billing')
                ->color($tenants ? 'success' : 'gray'),

            Stat::make('Targetable value', '$' . number_format($value))
                ->description('A+B weighted, monthly')
                ->color('success'),
        ];
    }
}
SALESPKG_EOF_7
echo "  wrote app/Filament/Resources/SalesProspectResource/Widgets/SalesFunnelWidget.php"

mkdir -p app/Models
cat > app/Models/SalesActivity.php <<'SALESPKG_EOF_8'
<?php
// MARKER-SALES-ACTIVITY

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesActivity extends Model
{
    use HasUuids;

    protected $table = 'sales_activities';

    protected $fillable = [
        'sales_prospect_id', 'type', 'stage_from', 'stage_to', 'body', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public const TYPES = [
        'note'         => 'Note',
        'email'        => 'Email',
        'call'         => 'Call',
        'demo'         => 'Demo',
        'follow_up'    => 'Follow-up',
        'stage_change' => 'Stage change',
        'system'       => 'System',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(SalesProspect::class, 'sales_prospect_id');
    }

    public function icon(): string
    {
        return match ($this->type) {
            'email'        => 'heroicon-o-envelope',
            'call'         => 'heroicon-o-phone',
            'demo'         => 'heroicon-o-presentation-chart-line',
            'follow_up'    => 'heroicon-o-clock',
            'stage_change' => 'heroicon-o-arrow-right-circle',
            'system'       => 'heroicon-o-bolt',
            default        => 'heroicon-o-chat-bubble-left',
        };
    }
}
SALESPKG_EOF_8
echo "  wrote app/Models/SalesActivity.php"

mkdir -p app/Models
cat > app/Models/SalesProspect.php <<'SALESPKG_EOF_9'
<?php
// MARKER-SALES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop you might sell Intake to. Platform-level, lives next to Tenant in the
 * master admin. When a prospect converts, set tenant_id and the funnel/MRR
 * roll-ups become real numbers instead of estimates.
 */
class SalesProspect extends Model
{
    use HasUuids;

    protected $table = 'sales_prospects';

    protected $fillable = [
        'shop', 'city', 'state', 'region', 'loop', 'route_loop', 'type', 'primary_type',
        'priority', 'verified', 'business_status', 'lead_score', 'rating', 'rating_count',
        'stage', 'next_action_on', 'next_action', 'last_contacted_at', 'lost_reason',
        'tenant_id',
        'owner_contact', 'phone', 'email', 'website', 'address',
        'best_ask', 'source', 'source_url', 'google_maps_url', 'notes',
        'google_place_id', 'lat', 'lng',
    ];

    protected $casts = [
        'loop'              => 'integer',
        'verified'          => 'boolean',
        'lead_score'        => 'integer',
        'rating'            => 'decimal:1',
        'rating_count'      => 'integer',
        'next_action_on'    => 'date',
        'last_contacted_at' => 'datetime',
        'lat'               => 'decimal:6',
        'lng'               => 'decimal:6',
    ];

    /** Ordered pipeline. Order matters — used for funnel cumulative counts. */
    public const STAGES = [
        'prospect'    => 'Prospect',
        'verifying'   => 'Verifying',
        'contacted'   => 'Contacted',
        'demo_booked' => 'Demo booked',
        'demo_done'   => 'Demo done',
        'trial'       => 'Trial',
        'won'         => 'Won',
        'lost'        => 'Lost',
    ];

    public const PRIORITIES = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'];

    /** Google Places business_status values. null = not yet discovered via Places. */
    public const BUSINESS_STATUSES = [
        'OPERATIONAL'        => 'Operational',
        'CLOSED_TEMPORARILY' => 'Closed (temp)',
        'CLOSED_PERMANENTLY' => 'Closed (permanent)',
    ];

    /**
     * Columns the national Places import is allowed to refresh on an EXISTING row.
     * Everything not in here (stage, next_action*, tenant_id, verified, priority,
     * lead_score, human notes, email, owner_contact) is human-owned and never
     * touched by a re-import — so running the pipeline again never clobbers work.
     */
    public const DISCOVERY_COLUMNS = [
        'business_status', 'rating', 'rating_count', 'lat', 'lng',
        'google_maps_url', 'primary_type', 'address', 'google_place_id',
    ];

    /** The 9 Washington driving loops. Static reference — rarely changes. */
    public const LOOPS = [
        1 => 'Spokane / Inland NW',
        2 => 'Palouse / Tri-Cities / SE WA',
        3 => 'Central WA / Wenatchee / Methow',
        4 => 'North Sound / Bellingham / Skagit / Islands',
        5 => 'Seattle Core',
        6 => 'Eastside / I-90 Corridor',
        7 => 'South Sound / Olympia',
        8 => 'Kitsap / Olympic Peninsula / Coast',
        9 => 'Southwest WA / Columbia River',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SalesActivity::class)->latest('occurred_at');
    }

    public function scopeOpen($q) { return $q->whereNotIn('stage', ['won', 'lost']); }

    public function scopeOperational($q)
    {
        // OPERATIONAL or not-yet-discovered (null) — i.e. "not known to be closed".
        return $q->where(function ($w) {
            $w->whereNull('business_status')->orWhere('business_status', 'OPERATIONAL');
        });
    }

    public function isOperational(): bool
    {
        return $this->business_status === null || $this->business_status === 'OPERATIONAL';
    }

    public function businessStatusLabel(): ?string
    {
        return $this->business_status
            ? (self::BUSINESS_STATUSES[$this->business_status] ?? $this->business_status)
            : null;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst($this->stage);
    }

    public function stageIndex(): int
    {
        $i = array_search($this->stage, array_keys(self::STAGES), true);
        return $i === false ? 0 : (int) $i;
    }

    public function loopLabel(): ?string
    {
        return $this->loop ? (self::LOOPS[$this->loop] ?? null) : null;
    }

    /**
     * Move to a new stage and log it. If the new stage is won/lost we stamp
     * last_contacted_at; otherwise leave the work queue alone.
     */
    public function advanceTo(string $stage, ?string $note = null): void
    {
        $from = $this->stage;
        if ($from === $stage) {
            return;
        }
        $this->update(['stage' => $stage]);
        $this->activities()->create([
            'type'       => 'stage_change',
            'stage_from' => $from,
            'stage_to'   => $stage,
            'body'       => $note,
        ]);
    }
}
SALESPKG_EOF_9
echo "  wrote app/Models/SalesProspect.php"

mkdir -p database/migrations
cat > database/migrations/2026_06_25_000001_create_sales_prospects_table.php <<'SALESPKG_EOF_10'
<?php
// MARKER-SALES-CORE — Sales channel: prospect pipeline for the master admin.
// Platform-level (NOT tenant-scoped). One row per shop you might sell Intake to.
// The killer column is tenant_id: once a prospect signs up, link it and the
// dashboard funnel + MRR roll-ups become real instead of guesses.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_prospects', function (Blueprint $t) {
            $t->uuid('id')->primary();

            // Identity / geography
            $t->string('shop', 191);
            $t->string('city', 120)->nullable();
            $t->string('region', 120)->nullable();
            $t->unsignedTinyInteger('loop')->nullable();      // 1..9 trip loop
            $t->string('type', 120)->nullable();              // 'Independent', 'Service', 'E-bike specialist'...

            // Qualification
            $t->char('priority', 1)->default('B');            // A | B | C | D
            $t->boolean('verified')->default(false);
            $t->unsignedSmallInteger('lead_score')->default(0); // 0..110

            // Pipeline
            $t->string('stage', 24)->default('prospect');     // see SalesProspect::STAGES
            $t->date('next_action_on')->nullable();           // drives the "work queue"
            $t->string('next_action', 191)->nullable();
            $t->timestamp('last_contacted_at')->nullable();
            $t->text('lost_reason')->nullable();

            // The bridge: links to a real Tenant once they sign up.
            $t->uuid('tenant_id')->nullable();

            // Contact (filled as you work the list)
            $t->string('owner_contact', 191)->nullable();
            $t->string('phone', 64)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('website', 255)->nullable();

            // Provenance / context
            $t->string('best_ask', 191)->nullable();
            $t->string('source', 120)->nullable();
            $t->string('source_url', 512)->nullable();
            $t->text('notes')->nullable();

            // Map
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();

            $t->timestamps();

            $t->index(['stage', 'priority']);
            $t->index(['loop', 'priority']);
            $t->index('next_action_on');
            $t->index('tenant_id');
            $t->unique(['shop', 'city']);

            $t->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('sales_prospects'); }
};
SALESPKG_EOF_10
echo "  wrote database/migrations/2026_06_25_000001_create_sales_prospects_table.php"

mkdir -p database/migrations
cat > database/migrations/2026_06_25_000002_create_sales_activities_table.php <<'SALESPKG_EOF_11'
<?php
// MARKER-SALES-ACTIVITY — Sales channel: per-prospect activity log (the playbook in motion).
// Every note / email / call / demo / stage change is one row. This is what a
// spreadsheet can't do: a timestamped trail per shop that also auto-stamps the
// next follow-up date back onto sales_prospects.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('sales_prospect_id');

            $t->string('type', 24);                  // note | email | call | demo | follow_up | stage_change | system
            $t->string('stage_from', 24)->nullable();
            $t->string('stage_to', 24)->nullable();
            $t->text('body')->nullable();
            $t->timestamp('occurred_at')->useCurrent();

            $t->timestamps();

            $t->index(['sales_prospect_id', 'occurred_at']);
            $t->foreign('sales_prospect_id')->references('id')->on('sales_prospects')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('sales_activities'); }
};
SALESPKG_EOF_11
echo "  wrote database/migrations/2026_06_25_000002_create_sales_activities_table.php"

mkdir -p database/migrations
cat > database/migrations/2026_06_25_000003_add_places_fields_to_sales_prospects.php <<'SALESPKG_EOF_12'
<?php
// MARKER-SALES-PLACES — Make sales_prospects national-ready.
// Adds the fields the Google Places pipeline produces (place id, business status,
// rating, street address, state, route-loop label) so master.csv can be imported
// directly. Additive + guarded: runs in timestamp order AFTER 000001 whether or
// not the base table was already deployed, and is safe to re-run.
//
// Also drops the (shop, city) UNIQUE constraint: nationally that's a liability
// (two real shops can share a name+city), so identity moves to google_place_id.
// firstOrCreate in the seeder does a SELECT-then-INSERT and does not depend on
// the DB constraint, so the WA seeder keeps working unchanged.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'google_place_id')) {
                $t->string('google_place_id', 191)->nullable()->after('id');
            }
            if (! Schema::hasColumn('sales_prospects', 'state')) {
                $t->string('state', 64)->nullable()->after('city');           // 'WA', 'OR', 'ID'...
            }
            if (! Schema::hasColumn('sales_prospects', 'route_loop')) {
                $t->string('route_loop', 120)->nullable()->after('loop');      // national loop label, vs WA int loop
            }
            if (! Schema::hasColumn('sales_prospects', 'address')) {
                $t->string('address', 255)->nullable()->after('website');      // street address from Places
            }
            if (! Schema::hasColumn('sales_prospects', 'business_status')) {
                $t->string('business_status', 32)->nullable()->after('verified'); // OPERATIONAL | CLOSED_* | null
            }
            if (! Schema::hasColumn('sales_prospects', 'rating')) {
                $t->decimal('rating', 2, 1)->nullable()->after('lead_score');  // 0.0..5.0
            }
            if (! Schema::hasColumn('sales_prospects', 'rating_count')) {
                $t->unsignedInteger('rating_count')->nullable()->after('rating');
            }
            if (! Schema::hasColumn('sales_prospects', 'primary_type')) {
                $t->string('primary_type', 64)->nullable()->after('type');     // Places primaryType
            }
            if (! Schema::hasColumn('sales_prospects', 'google_maps_url')) {
                $t->string('google_maps_url', 512)->nullable()->after('source_url');
            }
        });

        // Identity moves to google_place_id. Add a unique index (nullable → many
        // NULLs allowed in MySQL, fine for the hand-entered WA rows).
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->unique('google_place_id', 'sales_prospects_place_id_unique');
            } catch (\Throwable $e) {
                // already present — ignore
            }
        });

        // Drop the old (shop, city) UNIQUE; keep the lookup as a plain index.
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->dropUnique('sales_prospects_shop_city_unique');
            } catch (\Throwable $e) {
                // not present (fresh DB built after this patch) — ignore
            }
        });
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->index(['shop', 'city'], 'sales_prospects_shop_city_index');
            } catch (\Throwable $e) {
                // already present — ignore
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->index('state', 'sales_prospects_state_index'); } catch (\Throwable $e) {}
            try { $t->index('business_status', 'sales_prospects_status_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropUnique('sales_prospects_place_id_unique'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_state_index'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_status_index'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_shop_city_index'); } catch (\Throwable $e) {}

            foreach ([
                'google_place_id', 'state', 'route_loop', 'address',
                'business_status', 'rating', 'rating_count', 'primary_type', 'google_maps_url',
            ] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
SALESPKG_EOF_12
echo "  wrote database/migrations/2026_06_25_000003_add_places_fields_to_sales_prospects.php"

mkdir -p database/seeders
cat > database/seeders/SalesProspectSeeder.php <<'SALESPKG_EOF_13'
<?php
// MARKER-SALES-SEEDER — Seed the Washington bike-shop sales territory (213 prospects).
// Idempotent: firstOrCreate keyed on (shop, city). Stage stays at the table default
// ('prospect') so re-running never clobbers pipeline progress you've already made.

namespace Database\Seeders;

use App\Models\SalesProspect;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SalesProspectSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $r) {
            SalesProspect::firstOrCreate(
                ['shop' => $r['shop'], 'city' => $r['city']],
                array_merge($r, ['id' => (string) Str::uuid()]),
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['shop'=>'Argonne Cycle','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; likely worth verifying current operations.','lead_score'=>100,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Bicycle Butler','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Service / Mobile / Specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-oriented prospect; may value intake/work-order flow.','lead_score'=>70,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Matthew Larsen Wheelbuilding','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Specialty / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Wheelbuilding specialty; likely workflow-light but good industry contact.','lead_score'=>70,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'North Division Bicycle','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.northdivision.com/','notes'=>'Official site shows North Division Bicycle location and service/retail; NDB also lists Hillyard Bicycle.','lead_score'=>110,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Hillyard Bicycle','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.northdivision.com/','notes'=>'Listed on North Division Bicycle official site as second location.','lead_score'=>110,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Spoke \'N Sport','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.spokensportinc.net/','notes'=>'Official site lists bike repair/service and rentals.','lead_score'=>110,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Spokane Alpine Haus Bike Shop','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Outdoor / Bike service','priority'=>'B','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.thespokanealpinehaus.com/services/bike/','notes'=>'Full-service bike shop according to official site.','lead_score'=>80,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Solnix Spokane','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Specialty / Directory only','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing only; verify active and target fit.','lead_score'=>35,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'This Bike Shop','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing only; verify active.','lead_score'=>70,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'The Bike Hub','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'D','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'User-related/owned-known shop; use as proof/demo environment, not outreach target.','lead_score'=>25,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Wheel Sport Bicycles - South','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.wheelsportbikes.com/','notes'=>'Official site says family-owned sales/service with multiple Spokane-area locations.','lead_score'=>110,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Wheel Sport Bicycles - North','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike/Official site','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/ | https://www.wheelsportbikes.com/','notes'=>'Directory lists multiple Wheel Sport locations; confirm current exact branch list.','lead_score'=>100,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Wheel Sport Bicycles - East','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike/Official site','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/ | https://www.wheelsportbikes.com/','notes'=>'Directory lists East/Valley branches; verify current branch list.','lead_score'=>100,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Wheel Sport Re-Cyclery','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent / Used / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Verify current operations and whether same ownership as Wheel Sport.','lead_score'=>70,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'Wheel Sport Valley','city'=>'Spokane Valley','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike/Official site','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/ | https://www.wheelsportbikes.com/','notes'=>'Directory lists Valley branch; verify current branch list.','lead_score'=>100,'lat'=>47.6732,'lng'=>-117.2394],
            ['shop'=>'Mojo Cyclery','city'=>'Spokane Valley','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.mojocyclery.com/','notes'=>'Official site says full-service bicycle shop in Spokane Valley since 2017.','lead_score'=>110,'lat'=>47.6732,'lng'=>-117.2394],
            ['shop'=>'Velofix Spokane/CDA','city'=>'Spokane / CDA','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'Mobile service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Mobile/mechanic model; good service workflow prospect but not traditional POS.','lead_score'=>70,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'REI Spokane Bike Shop','city'=>'Spokane','region'=>'Spokane / Inland NW','loop'=>1,'type'=>'National chain','priority'=>'C','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.rei.com/stores/spokane/bike-shop','notes'=>'REI has corporate procurement; useful for intel more than first-wave sales.','lead_score'=>45,'lat'=>47.6588,'lng'=>-117.426],
            ['shop'=>'B & L Bicycles','city'=>'Pullman','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'College-town service/retail prospect.','lead_score'=>100,'lat'=>46.7313,'lng'=>-117.1796],
            ['shop'=>'Bicycle Barn','city'=>'Walla Walla','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.bicyclebarn.com/','notes'=>'Official site says full-service shop serving Walla Walla Valley since 1975.','lead_score'=>110,'lat'=>46.0646,'lng'=>-118.343],
            ['shop'=>'Allegro Cyclery','city'=>'Walla Walla','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result/Facebook','source_url'=>'https://www.facebook.com/allegrocyclery/','notes'=>'Facebook result references tune-ups/builds/repairs; verify current hours.','lead_score'=>70,'lat'=>46.0646,'lng'=>-118.343],
            ['shop'=>'Whitman Outdoor Program Bike Shop','city'=>'Walla Walla','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'University / Service','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://www.whitman.edu/life-at-whitman/outdoor-program/rental-shop/bike-shop','notes'=>'Likely not a commercial POS fit; good local contact.','lead_score'=>15,'lat'=>46.0646,'lng'=>-118.343],
            ['shop'=>'Kennewick Cycle & Fitness Equipment','city'=>'Kennewick','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; likely active but verify.','lead_score'=>100,'lat'=>46.2113,'lng'=>-119.1372],
            ['shop'=>'Trek Bicycle Kennewick','city'=>'Kennewick','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>46.2113,'lng'=>-119.1372],
            ['shop'=>'Scotts Cycle & Sport','city'=>'Kennewick','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>46.2113,'lng'=>-119.1372],
            ['shop'=>'Greenies Bike Shop','city'=>'Richland','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Tri-Cities independent prospect; verify active.','lead_score'=>100,'lat'=>46.2857,'lng'=>-119.2845],
            ['shop'=>'Markee\'s Cycling Center','city'=>'Kennewick / Tri-Cities','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://markeescyclingcenter.com/','notes'=>'Search result says used/new bikes and large e-bike selection in Tri-Cities.','lead_score'=>100,'lat'=>46.2113,'lng'=>-119.1372],
            ['shop'=>'Bearded Monkey Cycling','city'=>'Yakima','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://www.trekbikes.com/us/en_US/store/358534/','notes'=>'Trek retailer result gives Yakima address/phone; good central WA prospect.','lead_score'=>110,'lat'=>46.6021,'lng'=>-120.5059],
            ['shop'=>'Renewal Bicycle Project','city'=>'Yakima','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Nonprofit / Service','priority'=>'B','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://www.renewalbicycleproject.com/','notes'=>'Search result says expert bicycle repair and parts sales.','lead_score'=>80,'lat'=>46.6021,'lng'=>-120.5059],
            ['shop'=>'Recycle Shop','city'=>'Ellensburg','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://www.recycleshop.us/','notes'=>'Official/search result lists 415 N Main St Ellensburg, bikes/accessories/components.','lead_score'=>110,'lat'=>46.9965,'lng'=>-120.5478],
            ['shop'=>'Ellensburg Bicycle','city'=>'Ellensburg','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'News/search result','source_url'=>'https://cwuobserver.com/19762/scene/new-bike-repair-shop-ellensburg-bicycle-opens-for-business/','notes'=>'2021 news result says bike repair shop opened; verify active.','lead_score'=>70,'lat'=>46.9965,'lng'=>-120.5478],
            ['shop'=>'The Bike Shop','city'=>'Okanogan','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Small-market prospect; verify active.','lead_score'=>70,'lat'=>48.3612,'lng'=>-119.5829],
            ['shop'=>'Ride-A-Bike Bicycle Shop','city'=>'Clarkston','region'=>'Eastern / SE Washington','loop'=>2,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result/Facebook','source_url'=>'https://www.facebook.com/groups/1505446706423489/posts/2923126441322168/','notes'=>'Social result only; verify current operations.','lead_score'=>70,'lat'=>46.4163,'lng'=>-117.0454],
            ['shop'=>'Arlberg Sports','city'=>'Wenatchee','region'=>'Central Washington','loop'=>3,'type'=>'Outdoor / Bike service','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.arlbergsports.com/','notes'=>'Official site lists bike service/repair and rentals.','lead_score'=>110,'lat'=>47.4235,'lng'=>-120.3103],
            ['shop'=>'Full Circle Cycle','city'=>'Wenatchee','region'=>'Central Washington','loop'=>3,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.4235,'lng'=>-120.3103],
            ['shop'=>'Ridge Cyclesport','city'=>'Wenatchee','region'=>'Central Washington','loop'=>3,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.4235,'lng'=>-120.3103],
            ['shop'=>'Trek Bicycle Wenatchee','city'=>'Wenatchee','region'=>'Central Washington','loop'=>3,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.trekbikes.com/us/en_US/retail/wenatchee/','notes'=>'Trek official result says sales/service and 24-hour repair on any brand.','lead_score'=>45,'lat'=>47.4235,'lng'=>-120.3103],
            ['shop'=>'Penny Bike Co.','city'=>'Wenatchee','region'=>'Central Washington','loop'=>3,'type'=>'E-bike / Retail','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://www.facebook.com/groups/togetherwenatchee/posts/2012007139339700/','notes'=>'Mentioned in local search result; verify active/retail model.','lead_score'=>70,'lat'=>47.4235,'lng'=>-120.3103],
            ['shop'=>'Downtown Bike','city'=>'Cashmere','region'=>'Central Washington','loop'=>3,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Smaller-market independent; likely owner-led.','lead_score'=>100,'lat'=>47.5223,'lng'=>-120.4698],
            ['shop'=>'Das Rad Haus','city'=>'Leavenworth','region'=>'Central Washington','loop'=>3,'type'=>'Independent / MTB destination','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Destination-market shop; verify active.','lead_score'=>100,'lat'=>47.5962,'lng'=>-120.6615],
            ['shop'=>'Eastside Cycleworks','city'=>'Leavenworth','region'=>'Central Washington','loop'=>3,'type'=>'Independent / MTB destination','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://eastsidecycleworks.com/','notes'=>'Official site says full service bike shop specializing in repairs, sales and demos.','lead_score'=>110,'lat'=>47.5962,'lng'=>-120.6615],
            ['shop'=>'Eurosports Cycling','city'=>'Leavenworth','region'=>'Central Washington','loop'=>3,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.5962,'lng'=>-120.6615],
            ['shop'=>'Leavenworth Ebikes','city'=>'Leavenworth / Plain','region'=>'Central Washington','loop'=>3,'type'=>'E-bike rental/tour','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://leavenworth.org/experience/cycling-mountain-biking/','notes'=>'Tourism listing; likely rental workflow prospect.','lead_score'=>70,'lat'=>47.5962,'lng'=>-120.6615],
            ['shop'=>'Flow State Biking','city'=>'Leavenworth','region'=>'Central Washington','loop'=>3,'type'=>'Guide / Service','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://leavenworth.org/experience/cycling-mountain-biking/','notes'=>'Tourism listing; may be more guide than retail shop.','lead_score'=>35,'lat'=>47.5962,'lng'=>-120.6615],
            ['shop'=>'Methow Cycle & Sport','city'=>'Winthrop','region'=>'Central Washington','loop'=>3,'type'=>'Independent / destination','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Destination/recreation town; high service seasonality.','lead_score'=>100,'lat'=>48.476,'lng'=>-120.1879],
            ['shop'=>'North Cascades Cycle Werks','city'=>'Mazama','region'=>'Central Washington','loop'=>3,'type'=>'Independent / destination','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Destination/recreation town; verify active.','lead_score'=>100,'lat'=>48.5915,'lng'=>-120.4262],
            ['shop'=>'Ride Roslyn Bicycles','city'=>'Roslyn','region'=>'Central Washington','loop'=>3,'type'=>'Independent / destination','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Destination-market shop; verify active.','lead_score'=>100,'lat'=>47.2226,'lng'=>-120.994],
            ['shop'=>'Alleycat Bike Shop','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Bellingham has strong MTB/service market; good target.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Cafe Velo','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / Cafe','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify retail/service fit.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Earl\'s Bike Shop','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Established shop; likely owner/manager-led.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Fairhaven Bicycles','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Strong independent prospect.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'REI Bellingham Bike Shop','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Bellingham Cycle Works','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Bikesport Bellingham','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Brown Dog Bike','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Drop N Zone','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'MTB / Specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Fanatik Bike Co.','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'MTB specialty / Service','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.fanatikbike.com/','notes'=>'Official site says custom MTB builds, full maintenance, rentals.','lead_score'=>110,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Jack\'s Bicycle Center','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Robert\'s Bicycle Repair','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-focused prospect; verify active.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Roiba Gear','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Specialty / Gear','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify target fit.','lead_score'=>35,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Stash Cycles','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'The Hub','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'The Kona Bike Shop','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Brand / Specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Factory/brand relationship; verify sales decision path.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'The Lost Co.','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'MTB specialty / E-commerce','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Online/retail specialty; might care about service intake less than fulfillment.','lead_score'=>70,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'TrailSide Bike Co.','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / MTB','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory/search result; good MTB-service prospect.','lead_score'=>100,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Transition Bikes Factory Showroom and Demo Center','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Factory showroom / Demo','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Brand/demo center; useful for network intel.','lead_score'=>35,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Trek Bicycle Bellingham','city'=>'Bellingham','region'=>'North Sound / Islands','loop'=>4,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>48.7519,'lng'=>-122.4787],
            ['shop'=>'Arlington Velo Sport','city'=>'Arlington','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'North Sound independent; verify active.','lead_score'=>100,'lat'=>48.1987,'lng'=>-122.1251],
            ['shop'=>'Hidden Wave','city'=>'Burlington','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.4757,'lng'=>-122.3287],
            ['shop'=>'Skagit Cycle Center - Burlington','city'=>'Burlington','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory lists Skagit Cycle; verify current branches.','lead_score'=>100,'lat'=>48.4757,'lng'=>-122.3287],
            ['shop'=>'Skagit Cycle Center - Anacortes','city'=>'Anacortes','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory lists Anacortes branch; verify current.','lead_score'=>100,'lat'=>48.5126,'lng'=>-122.6127],
            ['shop'=>'Skagit Cycle / Oak Harbor','city'=>'Oak Harbor','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory lists Oak Harbor; verify current.','lead_score'=>100,'lat'=>48.2932,'lng'=>-122.6432],
            ['shop'=>'Bicycles Northwest','city'=>'Oak Harbor','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.2932,'lng'=>-122.6432],
            ['shop'=>'Lenny\'s Bike Shop','city'=>'Ferndale','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.8465,'lng'=>-122.5912],
            ['shop'=>'Morris Custom Bicycles','city'=>'Ferndale','region'=>'North Sound / Islands','loop'=>4,'type'=>'Custom / Builder','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Specialty builder; likely not POS fit.','lead_score'=>35,'lat'=>48.8465,'lng'=>-122.5912],
            ['shop'=>'Shuksan Cycles','city'=>'Sumas','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>48.9999,'lng'=>-122.2643],
            ['shop'=>'Island Bicycle','city'=>'Friday Harbor','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / island','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Island-market shop; plan ferry logistics.','lead_score'=>100,'lat'=>48.5343,'lng'=>-123.0171],
            ['shop'=>'Village Cycles','city'=>'Lopez Island','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / island','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Island-market shop; plan ferry logistics.','lead_score'=>100,'lat'=>48.4815,'lng'=>-122.8915],
            ['shop'=>'Wildlife Cycles','city'=>'Eastsound','region'=>'North Sound / Islands','loop'=>4,'type'=>'Independent / island','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Island-market shop; plan ferry logistics.','lead_score'=>100,'lat'=>48.6973,'lng'=>-122.9043],
            ['shop'=>'20/20 Cycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Core Seattle independent; strong sales target.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Aaron\'s Bicycle Repair / Rat City Bikes','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service / Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-first prospect; likely strong Intake fit.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Alki Bike & Board','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent retail/service prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Alpine Hut','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent retail/service prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Angle Lake Cyclery','city'=>'Seattle / SeaTac','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Ascent Cycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'ASUW Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'University / Service','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'University shop; not likely subscription POS target.','lead_score'=>15,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Back Alley Bike Repair','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-focused prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'The Bicycle Repair Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-focused prospect; verify duplicate listings.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Bicycle Doctor','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Bike Swift','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike / Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Participating WE-bike/e-bike signal in search; good e-bike service workflow prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Bike Works','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Nonprofit / Community Shop','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Community shop; may need nonprofit pricing.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Bikesport Seattle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Branford Bike','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Specialty / Retail','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Verify active/local operations.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Cassette Club','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent / Specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Counterbalance Bicycles - Queen Anne','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Counterbalance Bicycles - University Village','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Cycle and Coffee','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent / Cafe','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade notes formerly PIM Bicycles; verify service/POS fit.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Cycle Therapy Alki','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Dave\'s Bicycle Repair','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Facebook source in Cascade; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Electric and Folding Bikes Northwest','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike / Folding specialist','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'E-bike/folding service flow likely strong fit.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Evelo Electric Bicycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike brand / showroom','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Brand/showroom; verify local retail/service model.','lead_score'=>35,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'evo Seattle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Retail chain / Outdoor','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate-ish; likely harder procurement.','lead_score'=>35,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Fluidride','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'MTB / Coaching / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Specialty MTB org; verify retail/service fit.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Free Range Cycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'AP article notes service/repair/accessory focus; strong Intake fit.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Gregg\'s Greenlake Cycle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Major independent chain; good but may have existing systems.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Hello Bicycle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Hilltopper Electric Bike','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike specialist','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike specialist; verify service/retail model.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'JRA Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'MBR Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent / MTB','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'MTB/service-heavy target.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'(mend)bicycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-forward prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Mello Fellos Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade notes formerly Velo; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Métier Seattle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Bike fitting / Cafe / Retail','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'May be fit/cafe; verify software fit.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Montlake Bicycle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Established independent prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Peloton Bicycle Shop & Cafe','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent / Cafe','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Independent shop/cafe; likely owner-led.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Rad Power Bikes','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Brand / E-bike','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike brand/corporate; not first-wave local sales target.','lead_score'=>35,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Recycled Cycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Large/known Seattle shop; strong but system-mature prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'REI Seattle Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Ride Bicycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent prospect; verify exact current locations.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Seattle E-Bike','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike specialist','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike service/retail target.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Seattle Electric Bike','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'E-bike specialist / multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike multi-location target.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Second Gear Sports','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Used / Outdoor','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Used gear/retail; verify bike-service fit.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Second Ascent','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Outdoor / Bike','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Outdoor retailer; verify bike service/POS fit.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'The Bikery','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Nonprofit / Community','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Community shop; may need nonprofit package.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'The Polka Dot Jersey','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent / Directory only','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Westside Bicycle','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Wrench Bicycle Workshop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Service-first prospect.','lead_score'=>100,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Performance Bike Shop','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Directory / likely stale','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Performance chain is likely stale/closed; verify before any visit.','lead_score'=>15,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Elliott Bay Bicycles','city'=>'Seattle','region'=>'Seattle','loop'=>5,'type'=>'Directory / likely stale','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing may be historic; verify before outreach.','lead_score'=>15,'lat'=>47.6062,'lng'=>-122.3321],
            ['shop'=>'Bellevue Bikes','city'=>'Bellevue','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Eastside retail/service prospect.','lead_score'=>100,'lat'=>47.6101,'lng'=>-122.2015],
            ['shop'=>'Gregg\'s Bellevue Cycle','city'=>'Bellevue','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Major independent chain; good but likely system-mature.','lead_score'=>100,'lat'=>47.6101,'lng'=>-122.2015],
            ['shop'=>'Rack N Road Bellevue','city'=>'Bellevue','region'=>'Eastside / I-90','loop'=>6,'type'=>'Rack / Bike accessory specialist','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'May not be bike-shop POS fit; verify.','lead_score'=>35,'lat'=>47.6101,'lng'=>-122.2015],
            ['shop'=>'REI Bellevue Bike Shop','city'=>'Bellevue','region'=>'Eastside / I-90','loop'=>6,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.6101,'lng'=>-122.2015],
            ['shop'=>'Seattle Electric Bike - Bothell','city'=>'Bothell','region'=>'Eastside / I-90','loop'=>6,'type'=>'E-bike specialist','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike specialist; strong service/booking fit.','lead_score'=>100,'lat'=>47.7623,'lng'=>-122.2054],
            ['shop'=>'Pacific Bike and Ski Duvall','city'=>'Duvall','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent chain; good target.','lead_score'=>100,'lat'=>47.7426,'lng'=>-121.9857],
            ['shop'=>'Downhill Zone','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'MTB / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'MTB service-heavy target.','lead_score'=>100,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Dirt Merchant Bikes','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'MTB / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'MTB service-heavy target; verify active.','lead_score'=>100,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Gerk\'s Ski & Cycle','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Outdoor','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Retail/service prospect.','lead_score'=>100,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Bicycle Center of Issaquah','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'Directory / likely stale','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing likely older; verify active.','lead_score'=>15,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Ride Bicycles Issaquah','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active/exact relation to Ride Seattle.','lead_score'=>100,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Progression Cycle - Klahanie','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; likely good Eastside service target.','lead_score'=>100,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Veloce Velo - Mountain Side Bikes','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'REI Issaquah Bike Shop','city'=>'Issaquah','region'=>'Eastside / I-90','loop'=>6,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.5301,'lng'=>-122.0326],
            ['shop'=>'Bothell Ski and Bike','city'=>'Kenmore','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Outdoor','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Retail/service prospect.','lead_score'=>100,'lat'=>47.7573,'lng'=>-122.244],
            ['shop'=>'BikePT','city'=>'Kenmore','region'=>'Eastside / I-90','loop'=>6,'type'=>'Bike fitting / PT','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Bike fitting/physical therapy; not standard shop POS.','lead_score'=>15,'lat'=>47.7573,'lng'=>-122.244],
            ['shop'=>'CorporeSanoPT','city'=>'Kenmore','region'=>'Eastside / I-90','loop'=>6,'type'=>'Bike fitting / PT','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Bike fitting/physical therapy; not standard shop POS.','lead_score'=>15,'lat'=>47.7573,'lng'=>-122.244],
            ['shop'=>'Gerard Cycles','city'=>'Kirkland','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Eastside independent target.','lead_score'=>100,'lat'=>47.6769,'lng'=>-122.206],
            ['shop'=>'Kirkland Bikes','city'=>'Kirkland','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Eastside independent target.','lead_score'=>100,'lat'=>47.6769,'lng'=>-122.206],
            ['shop'=>'Eastside Ski & Sport','city'=>'Kirkland','region'=>'Eastside / I-90','loop'=>6,'type'=>'Outdoor / Bike service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Verify bike-service fit.','lead_score'=>70,'lat'=>47.6769,'lng'=>-122.206],
            ['shop'=>'Northwest Bicycle Maple Valley','city'=>'Maple Valley','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Independent target.','lead_score'=>100,'lat'=>47.3923,'lng'=>-122.0454],
            ['shop'=>'Edge & Spoke Redmond','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Eastside independent target.','lead_score'=>100,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Element Cycles','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Eastside independent target.','lead_score'=>100,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Pedego Redmond','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'E-bike franchise','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike franchise; may need franchise/corporate approval.','lead_score'=>70,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Redmond Cycle','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Eastside independent target.','lead_score'=>100,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Edgar Bikes','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Mr. Crampy\'s Multisport','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Tri / Multisport','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Specialty shop; verify active.','lead_score'=>70,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Pedal Dynamics','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Fit / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Specialty fit/service; verify active.','lead_score'=>70,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Sammamish Valley Cyclery','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Service','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'REI Redmond Bike Shop','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Trek Bicycle Redmond','city'=>'Redmond','region'=>'Eastside / I-90','loop'=>6,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>47.674,'lng'=>-122.1215],
            ['shop'=>'Center Cycle','city'=>'Renton','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Large independent service/retail prospect.','lead_score'=>100,'lat'=>47.4829,'lng'=>-122.2171],
            ['shop'=>'GHY Bikes','city'=>'Renton','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.4829,'lng'=>-122.2171],
            ['shop'=>'Go Huck Yourself','city'=>'Renton','region'=>'Eastside / I-90','loop'=>6,'type'=>'MTB specialty','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'MTB/service target; verify current storefront.','lead_score'=>100,'lat'=>47.4829,'lng'=>-122.2171],
            ['shop'=>'REI Southcenter Bike Shop','city'=>'Tukwila','region'=>'Eastside / I-90','loop'=>6,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.4739,'lng'=>-122.2604],
            ['shop'=>'Trek Bicycle Tukwila Southcenter','city'=>'Tukwila','region'=>'Eastside / I-90','loop'=>6,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>47.4739,'lng'=>-122.2604],
            ['shop'=>'Pacific Bike and Ski Sammamish','city'=>'Sammamish','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent chain; good target.','lead_score'=>100,'lat'=>47.6163,'lng'=>-122.0356],
            ['shop'=>'Progression Cycle','city'=>'Sammamish','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade listing; verify active.','lead_score'=>100,'lat'=>47.6163,'lng'=>-122.0356],
            ['shop'=>'Bicycle Centres of Snohomish','city'=>'Snohomish','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent multi-location','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Good multi-location prospect.','lead_score'=>100,'lat'=>47.9129,'lng'=>-122.0982],
            ['shop'=>'evo Snoqualmie Pass','city'=>'Snoqualmie Pass','region'=>'Eastside / I-90','loop'=>6,'type'=>'Outdoor / Chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Outdoor chain; corporate procurement likely.','lead_score'=>35,'lat'=>47.3923,'lng'=>-121.4007],
            ['shop'=>'MTB HQ','city'=>'Snoqualmie','region'=>'Eastside / I-90','loop'=>6,'type'=>'MTB specialty','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active/retail model.','lead_score'=>70,'lat'=>47.5287,'lng'=>-121.8254],
            ['shop'=>'Northwest Bicycle Snoqualmie','city'=>'Snoqualmie','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.5287,'lng'=>-121.8254],
            ['shop'=>'South Fork North Bend','city'=>'North Bend','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent / MTB','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'MTB service target; verify active.','lead_score'=>100,'lat'=>47.4954,'lng'=>-121.7866],
            ['shop'=>'The Line | Bike Experience','city'=>'North Bend','region'=>'Eastside / I-90','loop'=>6,'type'=>'MTB / Demo / Retail','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Destination MTB service/demo target.','lead_score'=>100,'lat'=>47.4954,'lng'=>-121.7866],
            ['shop'=>'Woodinville Bicycle','city'=>'Woodinville','region'=>'Eastside / I-90','loop'=>6,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Eastside/Northshore independent target.','lead_score'=>100,'lat'=>47.7543,'lng'=>-122.1635],
            ['shop'=>'Cycle Therapy Kent','city'=>'Kent','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'South King independent prospect.','lead_score'=>100,'lat'=>47.3809,'lng'=>-122.2348],
            ['shop'=>'Phil\'s South Side Cyclery','city'=>'Federal Way','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.3223,'lng'=>-122.3126],
            ['shop'=>'2nd Cycle','city'=>'Tacoma','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Nonprofit / Community Shop','priority'=>'B','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Search result','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.2ndcycle.org/','notes'=>'Search result says full-service bike shop; may need nonprofit pricing.','lead_score'=>80,'lat'=>47.2529,'lng'=>-122.4443],
            ['shop'=>'Bonney Lake Bicycle Shop of Sumner','city'=>'Sumner','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'South Sound independent target.','lead_score'=>100,'lat'=>47.2032,'lng'=>-122.2401],
            ['shop'=>'Tacoma Bike & Ski','city'=>'Tacoma','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent / Outdoor','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://tacomabike.com/','notes'=>'Official site says comprehensive bike repair packages.','lead_score'=>110,'lat'=>47.2529,'lng'=>-122.4443],
            ['shop'=>'Opalescent Cyclery','city'=>'Tacoma','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent / Service','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.opalescentcyclery.com/','notes'=>'Official site shows Tacoma service/repair shop.','lead_score'=>110,'lat'=>47.2529,'lng'=>-122.4443],
            ['shop'=>'REI Tacoma Bike Shop','city'=>'Tacoma','region'=>'South Sound / Olympia','loop'=>7,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.2529,'lng'=>-122.4443],
            ['shop'=>'Trek Bicycle Tacoma North End','city'=>'Tacoma','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>47.2529,'lng'=>-122.4443],
            ['shop'=>'Trek Bicycle University Place','city'=>'University Place','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Search result','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.trekbikes.com/us/en_US/retail/tacoma-university-place/','notes'=>'Trek official result says sales/service; corporate procurement.','lead_score'=>45,'lat'=>47.2351,'lng'=>-122.5512],
            ['shop'=>'Trailside Cyclery','city'=>'Orting','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>47.0973,'lng'=>-122.2043],
            ['shop'=>'Eatonville Outdoor','city'=>'Eatonville','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Outdoor / Bike','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify bike-service fit.','lead_score'=>70,'lat'=>46.8693,'lng'=>-122.2654],
            ['shop'=>'Joy Ride Bicycles','city'=>'Lacey','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike/Search result','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/ | https://www.joyridebicycles.com/','notes'=>'Official result says founded in 2003 serving Lacey/Olympia cycling community.','lead_score'=>110,'lat'=>47.0343,'lng'=>-122.8232],
            ['shop'=>'Deschutes River Cyclery','city'=>'Olympia','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.drcyclery.com/','notes'=>'Official site says Olympia\'s oldest locally owned cycle shop.','lead_score'=>110,'lat'=>47.0379,'lng'=>-122.9007],
            ['shop'=>'Big Stump Bikes','city'=>'Olympia','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Independent / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Search result','source_url'=>'https://bcc.intercitytransit.com/node/124993','notes'=>'2025 Bike to Work event listing gives Olympia address; verify current.','lead_score'=>100,'lat'=>47.0379,'lng'=>-122.9007],
            ['shop'=>'Trek Bicycle Olympia','city'=>'Olympia','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>47.0379,'lng'=>-122.9007],
            ['shop'=>'Trek Bicycle Olympia West','city'=>'Olympia','region'=>'South Sound / Olympia','loop'=>7,'type'=>'Brand-owned / chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Corporate/brand-owned; harder sell.','lead_score'=>35,'lat'=>47.0379,'lng'=>-122.9007],
            ['shop'=>'REI Olympia Bike Shop','city'=>'Olympia','region'=>'South Sound / Olympia','loop'=>7,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.0379,'lng'=>-122.9007],
            ['shop'=>'B.I.Cycle Shop','city'=>'Bainbridge Island','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent / island','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Good owner-led island prospect.','lead_score'=>100,'lat'=>47.6262,'lng'=>-122.5212],
            ['shop'=>'Classic Cycle','city'=>'Bainbridge Island','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent / Museum','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Specialty/museum angle; verify POS/service fit.','lead_score'=>70,'lat'=>47.6262,'lng'=>-122.5212],
            ['shop'=>'Infinity Cyclery','city'=>'Kitsap / Port Orchard?','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Cascade lists under Kitsap; verify exact city/address.','lead_score'=>100,'lat'=>null,'lng'=>null],
            ['shop'=>'REI Silverdale Bike Shop','city'=>'Silverdale','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'National chain','priority'=>'C','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Corporate procurement; intel target.','lead_score'=>35,'lat'=>47.6448,'lng'=>-122.6951],
            ['shop'=>'Sasquatch Cycle Works','city'=>'Poulsbo','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent / Service','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>100,'lat'=>47.7362,'lng'=>-122.6465],
            ['shop'=>'Bayview Bicycles','city'=>'Langley / Whidbey','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Whidbey island prospect; plan ferry logistics.','lead_score'=>100,'lat'=>48.0404,'lng'=>-122.4068],
            ['shop'=>'Vashon Bikes','city'=>'Vashon Island','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent / island','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Island shop; plan ferry logistics.','lead_score'=>100,'lat'=>47.4471,'lng'=>-122.4596],
            ['shop'=>'Spider\'s Ski & Sports','city'=>'Vashon Island','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Outdoor / Bike','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Verify bike-service fit.','lead_score'=>70,'lat'=>47.4471,'lng'=>-122.4596],
            ['shop'=>'Ben\'s Bikes Sequim','city'=>'Sequim','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Peninsula independent target.','lead_score'=>100,'lat'=>48.0795,'lng'=>-123.1021],
            ['shop'=>'Pedego Electric Bikes Sequim','city'=>'Sequim','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'E-bike franchise','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'E-bike franchise; may need franchise approval.','lead_score'=>70,'lat'=>48.0795,'lng'=>-123.1021],
            ['shop'=>'Bike Garage','city'=>'Olympic Peninsula','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Service / Directory','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Facebook source in Cascade; verify exact city and active status.','lead_score'=>70,'lat'=>null,'lng'=>null],
            ['shop'=>'Sound Bikes & Kayaks','city'=>'Port Angeles','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent / Outdoor','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Outdoor + bike service prospect.','lead_score'=>100,'lat'=>48.1181,'lng'=>-123.4307],
            ['shop'=>'The Broken Spoke','city'=>'Port Townsend','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade/Pinkbike','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops | https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Independent service/retail prospect.','lead_score'=>100,'lat'=>48.117,'lng'=>-122.7604],
            ['shop'=>'The ReCyclery','city'=>'Port Townsend','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Nonprofit / Community','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Community shop; may need nonprofit pricing.','lead_score'=>70,'lat'=>48.117,'lng'=>-122.7604],
            ['shop'=>'La Vogue Cyclery','city'=>'Hoquiam','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Coastal-market prospect; verify active.','lead_score'=>70,'lat'=>46.9809,'lng'=>-123.8893],
            ['shop'=>'Bob\'s Bike Shop','city'=>'Longview','region'=>'Kitsap / Peninsula / Coast','loop'=>8,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Could be paired with SW WA/Vancouver route; verify active.','lead_score'=>100,'lat'=>46.1382,'lng'=>-122.9382],
            ['shop'=>'Bike Clark County','city'=>'Vancouver','region'=>'Southwest Washington','loop'=>9,'type'=>'Nonprofit / Full-service shop','priority'=>'B','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://bikeclarkcounty.org/','notes'=>'Official result says full-service bicycle repair and retail shop.','lead_score'=>80,'lat'=>45.6387,'lng'=>-122.6615],
            ['shop'=>'Camas Bike and Sport','city'=>'Camas','region'=>'Southwest Washington','loop'=>9,'type'=>'Independent','priority'=>'A','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'SW WA independent target; verify active.','lead_score'=>100,'lat'=>45.5871,'lng'=>-122.3995],
            ['shop'=>'Cycle Gear','city'=>'Vancouver','region'=>'Southwest Washington','loop'=>9,'type'=>'Motorcycle / Powersports','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Cascade','source_url'=>'https://cascade.org/resources/finding-bike/bike-shops','notes'=>'Likely not bicycle-focused despite Cascade listing; verify fit before contacting.','lead_score'=>15,'lat'=>45.6387,'lng'=>-122.6615],
            ['shop'=>'Rollin Right Bike Repair and Service','city'=>'Vancouver','region'=>'Southwest Washington','loop'=>9,'type'=>'Service / Mobile?','priority'=>'B','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.rollinrightrepairs.com/','notes'=>'Official result says bike repair/service established in Vancouver in 2017.','lead_score'=>80,'lat'=>45.6387,'lng'=>-122.6615],
            ['shop'=>'Vancouver Cyclery','city'=>'Vancouver','region'=>'Southwest Washington','loop'=>9,'type'=>'Independent','priority'=>'A','verified'=>false,'best_ask'=>'15-min owner/service-manager demo','source'=>'Official site/search result','source_url'=>'https://www.vancouvercyclery.com/','notes'=>'Official/search result shows bike/e-bike brands and Vancouver location.','lead_score'=>110,'lat'=>45.6387,'lng'=>-122.6615],
            ['shop'=>'Salmon Creek Cycle Co.','city'=>'Vancouver / Salmon Creek','region'=>'Southwest Washington','loop'=>9,'type'=>'Independent','priority'=>'B','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing; verify active.','lead_score'=>70,'lat'=>45.6387,'lng'=>-122.6615],
            ['shop'=>'Valley Cycling and Fitness','city'=>'Unknown WA','region'=>'Southwest Washington','loop'=>9,'type'=>'Directory only','priority'=>'D','verified'=>true,'best_ask'=>'15-min owner/service-manager demo','source'=>'Pinkbike','source_url'=>'https://www.pinkbike.com/directory/list/washington/2/bike-shop/','notes'=>'Directory listing lacks clear city; verify before route planning.','lead_score'=>15,'lat'=>null,'lng'=>null],
        ];
    }
}
SALESPKG_EOF_13
echo "  wrote database/seeders/SalesProspectSeeder.php"


python3 - <<'PYREG'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:60]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

P = "app/Providers/Filament/AdminPanelProvider.php"
s = rd(P)
if "MARKER-SALES-REGISTER" in s:
    print("  panel registration already applied — skipping.")
else:
    edit(P,
    "use App\\Filament\\Resources\\TenantResource;",
    "use App\\Filament\\Resources\\SalesProspectResource; // MARKER-SALES-REGISTER\nuse App\\Filament\\Resources\\TenantResource;")
    edit(P,
    "                TenantResource::class,",
    "                SalesProspectResource::class, // MARKER-SALES-REGISTER\n                TenantResource::class,")
print("Registration done.")
PYREG

echo ""
echo "Done. Now run apply-sales-campaigns.sh."
