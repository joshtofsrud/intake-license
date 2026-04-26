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
    protected static ?string $model = RoadmapEntry::class;

    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Roadmap';
    protected static ?int    $navigationSort  = 21;
    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel       = 'Roadmap entry';
    protected static ?string $pluralModelLabel = 'Roadmap entries';
    protected static ?string $slug             = 'roadmap';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Entry')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->required()
                        ->options(RoadmapEntry::STATUSES)
                        ->default('next_up')
                        ->native(false),

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

                    Forms\Components\TextInput::make('rough_timeframe')
                        ->maxLength(64)
                        ->placeholder('this week / Q2 / when X')
                        ->helperText('Loose timing. Skip if you don\'t want to commit. Never give a hard date for unshipped work.'),

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
                Tables\Columns\TextColumn::make('rough_timeframe')->label('Timing'),
                Tables\Columns\TextColumn::make('display_order')->label('Order')->sortable(),
                Tables\Columns\IconColumn::make('is_published')->boolean()->label('Pub'),
            ])
            ->defaultSort('display_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\SelectFilter::make('status')->options(RoadmapEntry::STATUSES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
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
