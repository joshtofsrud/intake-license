<?php
// MARKER-LEDGER-ADMIN

namespace App\Filament\Resources\SalesAgencyResource\RelationManagers;

use App\Models\SalesCommissionEntry;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionEntries';
    protected static ?string $title = 'Commissions';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('collected_at')
                    ->label('Collected')->dateTime('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')->weight('semibold')
                    ->description(fn (SalesCommissionEntry $r) => $r->tenant?->subdomain),
                Tables\Columns\TextColumn::make('rep.name')
                    ->label('Rep')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount_collected_cents')
                    ->label('Collected $')->alignEnd()
                    ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2)),
                Tables\Columns\TextColumn::make('rate')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state * 100, 2), '0'), '.') . '%'),
                Tables\Columns\TextColumn::make('commission_cents')
                    ->label('Commission')->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                    ->summarize(Tables\Columns\Summarizers\Summarizer::make()
                        ->using(fn ($query) => '$' . number_format(((clone $query)->sum('commission_cents')) / 100, 2))),
                Tables\Columns\TextColumn::make('basis')
                    ->badge()
                    ->color(fn ($state) => $state === 'year1' ? 'primary' : 'success')
                    ->formatStateUsing(fn ($state) => $state === 'year1' ? 'Yr 1' : 'Residual'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesCommissionEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'paid'    => 'success',
                        'accrued' => 'warning',
                        default   => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesCommissionEntry::STATUSES)->default('accrued'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records
                        ->filter(fn (SalesCommissionEntry $r) => $r->status === 'accrued')
                        ->each(fn (SalesCommissionEntry $r) => $r->update(['status' => 'paid', 'paid_at' => now()]))),
            ])
            ->defaultSort('collected_at', 'desc');
    }
}
