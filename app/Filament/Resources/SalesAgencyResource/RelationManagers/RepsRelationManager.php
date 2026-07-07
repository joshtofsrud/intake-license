<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\RelationManagers;

use App\Models\SalesRep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RepsRelationManager extends RelationManager
{
    protected static string $relationship = 'reps';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\Select::make('role')
                ->options(SalesRep::ROLES)->default('rep')->native(false)->required(),
            Forms\Components\TextInput::make('email')->email()->maxLength(191),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')->native(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesRep::ROLES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'principal' ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('email')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->counts('prospects')->label('Prospects')->alignEnd(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('user_id')
                    ->label('Login')
                    ->formatStateUsing(fn () => 'Active')
                    ->badge()->color('success')
                    ->placeholder(fn (SalesRep $record) => $record->invited_at ? 'Invited ' . $record->invited_at->diffForHumans() : 'Not invited'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // MARKER-REPPANEL-INVITE — Team & access pattern: tokenized setup link
                Tables\Actions\Action::make('invite')
                    ->label(fn (SalesRep $record) => $record->invited_at ? 'Resend invite' : 'Invite')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (SalesRep $record) => filled($record->email) && $record->user_id === null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (SalesRep $record) => "Send a setup link to {$record->email}? The link is valid for 7 days.")
                    ->action(function (SalesRep $record) {
                        $token = Str::random(48);
                        $record->forceFill([
                            'invite_token' => hash('sha256', $token),
                            'invited_at'   => now(),
                        ])->save();

                        $url  = url('/rep-setup/' . $token);
                        $name = e($record->name);
                        $agency = e($record->agency?->name ?? 'Intake');
                        Mail::html(
                            "<p>Hi {$name},</p>" .
                            "<p>You've been invited to the <strong>Intake rep dashboard</strong> for {$agency}. " .
                            "Set your password to get started:</p>" .
                            "<p><a href=\"{$url}\">{$url}</a></p>" .
                            "<p>This link is valid for 7 days.</p>",
                            function ($message) use ($record) {
                                $message->to($record->email)->subject('Set up your Intake rep account');
                            }
                        );

                        Notification::make()->title("Invite sent to {$record->email}")->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
