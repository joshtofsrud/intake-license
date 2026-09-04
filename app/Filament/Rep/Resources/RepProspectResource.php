<?php
// MARKER-REPPANEL-RESOURCE — Prospects, scoped to the signed-in rep.
// Principal -> whole agency book. Rep -> their own prospects.
// Creating a prospect here IS deal registration: attribution is stamped
// automatically and cannot be pointed at another agency.

namespace App\Filament\Rep\Resources;

use App\Filament\Rep\Resources\RepProspectResource\Pages;
use App\Models\SalesProspect;
use App\Models\SalesRep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepProspectResource extends Resource
{
    protected static ?string $model = SalesProspect::class;

    protected static ?string $navigationIcon  = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'My prospects';
    protected static ?string $modelLabel      = 'prospect';
    protected static ?string $slug            = 'prospects';

    /** The signed-in user's rep record (memoized per request). */
    public static function currentRep(): ?SalesRep
    {
        static $rep = false;
        if ($rep === false) {
            $rep = SalesRep::with('agency')->where('user_id', auth()->id())->first();
        }
        return $rep ?: null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $rep = static::currentRep();

        if (! $rep) {
            return $query->whereRaw('1 = 0');
        }

        return $rep->role === 'principal'
            ? $query->where('agency_id', $rep->agency_id)
            : $query->where('sales_rep_id', $rep->id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Shop')->columns(2)->schema([
                Forms\Components\TextInput::make('shop')->required()->maxLength(191),
                Forms\Components\TextInput::make('city')->maxLength(120),
                Forms\Components\TextInput::make('state')->maxLength(64)->placeholder('WA / OR / ID…'),
                Forms\Components\Select::make('stage')
                    ->options(SalesProspect::STAGES)->default('prospect')
                    ->native(false)->required(),
            ]),

            Forms\Components\Section::make('Contact')->columns(2)->schema([
                Forms\Components\TextInput::make('owner_contact')->label('Owner / contact')->maxLength(191),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
                Forms\Components\TextInput::make('email')->email()->maxLength(191),
                Forms\Components\TextInput::make('website')->url()->maxLength(255),
            ]),

            Forms\Components\Section::make('Next step')->columns(2)->schema([
                Forms\Components\TextInput::make('next_action')->maxLength(191),
                Forms\Components\DatePicker::make('next_action_on')->native(false),
                Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Quote')->columns(2)->collapsed()
                ->description('Proposed subscription. Priced from live plan/add-on pricing; total snapshots on save.')
                ->schema([
                    Forms\Components\Select::make('quote_tier')
                        ->label('Base tier')->live()->native(false)
                        ->options(fn () => collect(\App\Support\PlanPricing::all())
                            ->filter(fn ($cents) => (int) $cents > 0)
                            ->map(fn ($cents, $key) => ucfirst($key) . ' — $' . number_format($cents / 100) . '/mo')
                            ->all())
                        ->placeholder('— no quote yet —'),
                    Forms\Components\ViewField::make('quote_addons') // MARKER-QUOTE-GROUPED
                        ->label('Add-ons')->columnSpanFull()->default([])
                        ->view('filament.forms.quote-grouped-addons', [
                            'rateLabel' => 'your yr-1 commission',
                        ]),
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
                Tables\Columns\TextColumn::make('rep.name')
                    ->label('Rep')->toggleable()
                    ->visible(fn () => static::currentRep()?->role === 'principal'),
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesProspect::STAGES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'won'   => 'success',
                        'trial' => 'info',
                        'lost'  => 'danger',
                        'demo_booked', 'demo_done' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('quote_monthly')
                    ->label('Quote')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => $state ? '$' . number_format($state) . '/mo' : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('next_action_on')
                    ->label('Next action')->date()->sortable()
                    ->description(fn (SalesProspect $r) => $r->next_action)
                    ->color(fn (SalesProspect $r) => $r->next_action_on && $r->next_action_on->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage')->options(SalesProspect::STAGES),
                Tables\Filters\Filter::make('due')
                    ->label('Due / overdue')
                    ->query(fn (Builder $query) => $query->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))
                    ->toggle(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('next_action_on', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\SalesProspectResource\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRepProspects::route('/'),
            'create' => Pages\CreateRepProspect::route('/create'),
            'edit'   => Pages\EditRepProspect::route('/{record}/edit'),
        ];
    }
}
