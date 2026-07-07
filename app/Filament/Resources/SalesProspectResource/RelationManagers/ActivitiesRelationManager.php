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
