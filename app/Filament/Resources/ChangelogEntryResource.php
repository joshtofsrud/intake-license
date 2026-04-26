<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChangelogEntryResource\Pages;
use App\Models\ChangelogEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Changelog entries — what shipped, by date.
 * Public site renders the published ones at intake.works/changelog.
 * Tenants see them inside their admin under "What's new".
 */
class ChangelogEntryResource extends Resource
{
    protected static ?string $model = ChangelogEntry::class;

    protected static ?string $navigationIcon  = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Changelog';
    protected static ?int    $navigationSort  = 20;
    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel       = 'Changelog entry';
    protected static ?string $pluralModelLabel = 'Changelog entries';
    protected static ?string $slug             = 'changelog';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Entry')
                ->schema([
                    Forms\Components\DatePicker::make('shipped_on')
                        ->required()
                        ->default(now())
                        ->native(false),

                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(191)
                        ->helperText('Short, action-oriented. "Side-by-side overlapping appointments" not "We added a feature for X".'),

                    Forms\Components\Select::make('category')
                        ->options([
                            'Calendar' => 'Calendar',
                            'Booking'  => 'Booking',
                            'Stripe'   => 'Stripe',
                            'Customer' => 'Customer',
                            'Workflow' => 'Workflow',
                            'Bugfix'   => 'Bugfix',
                            'Polish'   => 'Polish',
                        ])
                        ->placeholder('Optional category tag'),

                    Forms\Components\Textarea::make('body')
                        ->required()
                        ->rows(6)
                        ->helperText('What changed and why a shop owner would care. 2-4 sentences. No internal jargon.'),

                    Forms\Components\Toggle::make('is_published')
                        ->helperText('Visitors only see published entries.'),

                    Forms\Components\Toggle::make('is_highlighted')
                        ->label('Highlight at top')
                        ->helperText('Pin to the top with lime accent. Use sparingly.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shipped_on')->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\IconColumn::make('is_published')->boolean()->label('Pub'),
                Tables\Columns\IconColumn::make('is_highlighted')->boolean()->label('Pin'),
            ])
            ->defaultSort('shipped_on', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\SelectFilter::make('category')->options([
                    'Calendar' => 'Calendar', 'Booking' => 'Booking', 'Stripe' => 'Stripe',
                    'Customer' => 'Customer', 'Workflow' => 'Workflow', 'Bugfix' => 'Bugfix',
                    'Polish' => 'Polish',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListChangelogEntries::route('/'),
            'create' => Pages\CreateChangelogEntry::route('/create'),
            'edit'   => Pages\EditChangelogEntry::route('/{record}/edit'),
        ];
    }
}
