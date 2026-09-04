<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Filament\Resources;

use App\Filament\Resources\SalesChannelResource\Pages;
use App\Models\SalesChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesChannelResource extends Resource
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'crm';

    protected static ?string $model = SalesChannel::class;

    protected static ?string $navigationIcon  = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 10;
    protected static ?string $navigationLabel = 'Campaigns';
    protected static ?string $modelLabel      = 'campaign';
    protected static ?string $slug            = 'sales/campaigns';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Channel')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120),
                Forms\Components\Select::make('status')
                    ->options(SalesChannel::STATUSES)->default('draft')
                    ->native(false)->required(),
                Forms\Components\TextInput::make('best_ask')
                    ->label('Best first ask')->maxLength(255)->columnSpanFull()
                    ->placeholder('e.g. 15-min owner demo at the shop'),
            ]),

            Forms\Components\Section::make('Targeting')->columns(2)->schema([
                Forms\Components\TagsInput::make('categories')
                    ->label('Categories handled')
                    ->placeholder('Sales / Rental / Service')
                    ->suggestions(['Sales', 'Rental', 'Service', 'Mobile', 'Retail']),
                Forms\Components\TagsInput::make('business_types')
                    ->label('Business types')
                    ->placeholder('Full-service shop, Mobile service…'),
            ]),

            Forms\Components\Section::make('Qualification criteria')->schema([
                Forms\Components\Repeater::make('criteria')
                    ->hiddenLabel()
                    ->schema([
                        Forms\Components\TextInput::make('label')->required()->maxLength(80),
                        Forms\Components\TextInput::make('note')->maxLength(180),
                    ])->columns(2)->defaultItems(0)->reorderable()
                    ->addActionLabel('Add criterion'),
            ])->collapsed(),

            Forms\Components\Section::make('Playbook')->columns(2)->collapsed()->schema([
                Forms\Components\TagsInput::make('playbook')
                    ->label('Stage labels (display guidance)')
                    ->default(SalesChannel::DEFAULT_PLAYBOOK)
                    ->helperText('Pipeline mechanics stay system-wide; this is the channel\'s outreach language.'),
                Forms\Components\Textarea::make('notes')->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('prospects')) // MARKER-SALES-QUERYPARAM
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->sortable()->weight('semibold')
                    ->description(fn (SalesChannel $r) => $r->best_ask),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesChannel::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'draft'  => 'warning',
                        default  => 'gray',
                    }),
                Tables\Columns\TextColumn::make('categories')
                    ->badge()->separator(',')->toggleable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->label('Prospects')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('generated_by')
                    ->label('Origin')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state === 'claude' ? '✦ Claude draft' : 'Manual')
                    ->placeholder('Manual'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesChannel::STATUSES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesChannels::route('/'),
            'create' => Pages\CreateSalesChannel::route('/create'),
            'edit'   => Pages\EditSalesChannel::route('/{record}/edit'),
        ];
    }
}
