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
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'crm';

    protected static ?string $model = SalesProspect::class;

    protected static ?string $navigationIcon  = 'heroicon-o-flag';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Prospects';
    protected static ?int    $navigationSort  = 20;
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

                Forms\Components\Select::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \App\Models\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('— channel —'),
                Forms\Components\Select::make('agency_id')
                    ->label('Agency')->live()
                    ->options(fn () => \App\Models\SalesAgency::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('— house —'),
                Forms\Components\Select::make('sales_rep_id')
                    ->label('Rep')
                    ->options(fn (\Filament\Forms\Get $get) => $get('agency_id')
                        ? \App\Models\SalesRep::query()->where('agency_id', $get('agency_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->native(false)->placeholder('— unassigned —'),
                Forms\Components\TagsInput::make('categories')
                    ->label('Categories handled')
                    ->placeholder('Sales / Rental / Service')
                    ->suggestions(['Sales', 'Rental', 'Service', 'Mobile', 'Retail']),
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

            Forms\Components\Section::make('Quote')->columns(2)->collapsed()
                ->description('Proposed subscription — tier + add-ons. Priced from plan_prices and the addons table; monthly total snapshots on save.')
                ->schema([
                    Forms\Components\Select::make('quote_tier')
                        ->label('Base tier')->live()->native(false)
                        ->options(fn () => collect(config('intake.plan_prices', []))
                            ->filter(fn ($cents) => (int) $cents > 0)
                            ->map(fn ($cents, $key) => ucfirst($key) . ' — $' . number_format($cents / 100) . '/mo')
                            ->all())
                        ->placeholder('— no quote yet —'),
                    Forms\Components\ViewField::make('quote_addons') // MARKER-QUOTE-GROUPED
                        ->label('Add-ons')->columnSpanFull()->default([])
                        ->view('filament.forms.quote-grouped-addons', [
                            'rate'      => \App\Models\SalesProspect::COMMISSION_YEAR1,
                            'rateLabel' => 'yr-1 commission',
                        ]),
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

                Tables\Columns\TextColumn::make('quote_monthly')
                    ->label('Quote')->alignEnd()->sortable()->toggleable()
                    ->formatStateUsing(fn ($state, SalesProspect $r) => $state
                        ? '$' . number_format($state) . '/mo' . (count((array) $r->quote_addons) ? ' · ' . count((array) $r->quote_addons) . ' add-on' . (count((array) $r->quote_addons) > 1 ? 's' : '') : '')
                        : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('lead_score')
                    ->label('Score')->sortable()->alignEnd()
                    ->color(fn ($state) => $state >= 100 ? 'success' : ($state >= 70 ? 'info' : 'gray')),

                Tables\Columns\TextColumn::make('rep.name')
                    ->label('Rep')->toggleable()->placeholder('house')
                    ->description(fn (SalesProspect $r) => $r->agency?->name),

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
                Tables\Filters\SelectFilter::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \App\Models\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('agency_id')
                    ->label('Agency')
                    ->options(fn () => \App\Models\SalesAgency::query()->orderBy('name')->pluck('name', 'id')->all()),
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
                    ->query(fn (Builder $query) => $query->operational()) // MARKER-SALES-QUERYPARAM
                    ->toggle(),
                Tables\Filters\TernaryFilter::make('verified'),
                Tables\Filters\TernaryFilter::make('tenant_id')
                    ->label('Converted to tenant')
                    ->nullable()
                    ->trueLabel('Linked tenants')
                    ->falseLabel('Not yet tenants'),
                Tables\Filters\Filter::make('due')
                    ->label('Action due')
                    ->query(fn (Builder $query) => $query->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now())) // MARKER-SALES-QUERYPARAM
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

    // MARKER-SALES-WIDGETREG — registers the funnel widget as a Livewire component
    public static function getWidgets(): array
    {
        return [
            \App\Filament\Resources\SalesProspectResource\Widgets\SalesFunnelWidget::class,
        ];
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
