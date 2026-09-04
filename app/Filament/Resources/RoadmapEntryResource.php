<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoadmapEntryResource\Pages;
use App\Models\RoadmapEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Roadmap entries — what's coming, grouped by status.
 * Public site renders the published ones at intake.works/roadmap.
 * Tenants see them inside their admin under "What's coming".
 */
class RoadmapEntryResource extends Resource
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'marketing';

    protected static ?string $model = RoadmapEntry::class;

    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?string $navigationLabel = 'Roadmap';
    protected static ?int    $navigationSort  = 70;
    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel       = 'Roadmap entry';
    protected static ?string $pluralModelLabel = 'Roadmap entries';
    protected static ?string $slug             = 'roadmap';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Entry')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('status')
                            ->required()
                            ->options(RoadmapEntry::STATUSES)
                            ->default('next_up')
                            ->live()
                            ->native(false),

                        Forms\Components\Select::make('tier')
                            ->options(RoadmapEntry::TIERS)
                            ->placeholder('— uncategorized —')
                            ->native(false)
                            ->helperText('Internal grouping. Hidden from public roadmap.'),
                    ]),

                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(191),

                    Forms\Components\Select::make('category')
                        ->options([
                            'Calendar' => 'Calendar',
                            'Booking'  => 'Booking',
                            'Stripe'   => 'Stripe',
                            'Customer' => 'Customer',
                            'Workflow' => 'Workflow',
                            'Mobile'   => 'Mobile',
                            'Polish'   => 'Polish',
                        ])
                        ->placeholder('Optional category tag'),

                    Forms\Components\Textarea::make('body')
                        ->required()
                        ->rows(5)
                        ->helperText('Public-friendly framing. What this means for the shop, not internal scope details.'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('shipped_on')
                            ->label('Shipped on')
                            ->native(false)
                            ->visible(fn (\Filament\Forms\Get $get) => $get('status') === 'shipped')
                            ->helperText('When this actually shipped. Required for shipped items.'),

                        Forms\Components\DatePicker::make('target_month')
                            ->label('Target month')
                            ->native(false)
                            ->displayFormat('F Y')
                            ->format('Y-m-01')
                            ->visible(fn (\Filament\Forms\Get $get) => $get('status') !== 'shipped')
                            ->helperText('Pick any date in the target month. Displays as "July 2026" publicly.'),
                    ]),

                    Forms\Components\TextInput::make('rough_timeframe')
                        ->label('Rough timeframe (fallback)')
                        ->maxLength(64)
                        ->placeholder('soon / when X / no timeline')
                        ->helperText('Used when target month is too committal. Most useful on "considering" items.'),

                    Forms\Components\TextInput::make('display_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Manual sort within a status. Lower numbers come first.'),

                    Forms\Components\Toggle::make('is_published')
                        ->helperText('Visitors only see published entries.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tier')
                    ->label('Tier')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? "T{$state}" : '—')
                    ->color(fn ($state) => match($state) {
                        1 => 'info',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => RoadmapEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match($state) {
                        'shipped'      => 'success',
                        'in_progress'  => 'warning',
                        'next_up'      => 'info',
                        'considering'  => 'gray',
                        default        => 'gray',
                    }),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('display_timeframe')
                    ->label('When')
                    ->state(fn (RoadmapEntry $record) => $record->displayTimeframe() ?? '—'),
                Tables\Columns\TextColumn::make('display_order')->label('Order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_published')->label('Pub'),
            ])
            ->defaultSort('display_order')
            ->groups([
                Tables\Grouping\Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (RoadmapEntry $record) => $record->statusLabel())
                    ->collapsible(),
                Tables\Grouping\Group::make('tier')
                    ->label('Tier')
                    ->getTitleFromRecordUsing(fn (RoadmapEntry $record) => $record->tierLabel() ?? 'Uncategorized')
                    ->collapsible(),
                Tables\Grouping\Group::make('category')
                    ->label('Category')
                    ->collapsible(),
            ])
            ->defaultGroup('status')
            ->modifyQueryUsing(function (\Illuminate\Database\Eloquent\Builder $query) {
                // Status-aware multi-key ordering:
                //   shipped       → newest ship first
                //   in_progress   → target_month ascending
                //   next_up       → tier ASC, then target_month, then display_order
                //   considering   → display_order
                return $query->orderByRaw("
                    FIELD(status, 'in_progress', 'next_up', 'considering', 'shipped'),
                    CASE WHEN status = 'shipped'   THEN shipped_on   END DESC,
                    CASE WHEN status = 'next_up'   THEN tier         END ASC,
                    CASE WHEN status IN ('in_progress','next_up') THEN target_month END ASC,
                    display_order ASC
                ");
            })
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\SelectFilter::make('status')->options(RoadmapEntry::STATUSES),
                Tables\Filters\SelectFilter::make('tier')->options(RoadmapEntry::TIERS),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([
                Tables\Actions\BulkAction::make('publish')
                    ->label('Publish selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['is_published' => true])),
                Tables\Actions\BulkAction::make('unpublish')
                    ->label('Unpublish selected')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['is_published' => false])),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoadmapEntries::route('/'),
            'create' => Pages\CreateRoadmapEntry::route('/create'),
            'edit'   => Pages\EditRoadmapEntry::route('/{record}/edit'),
        ];
    }
}
