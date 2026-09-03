<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingNoticeTemplateResource\Pages;
use App\Models\BillingNoticeTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// MARKER-BILLING-NOTICES — every word a shop reads about billing, edited here.
class BillingNoticeTemplateResource extends Resource
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static ?string $model = BillingNoticeTemplate::class;

    protected static ?string $navigationIcon  = 'heroicon-o-envelope-open';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Billing notices';
    protected static ?int    $navigationSort  = 87;
    protected static ?string $modelLabel       = 'billing notice';
    protected static ?string $pluralModelLabel = 'billing notices';
    protected static ?string $slug             = 'billing-notices';

    public static function form(Form $form): Form
    {
        $tokens = collect(BillingNoticeTemplate::TOKENS)
            ->map(fn ($what, $token) => "{$token} — {$what}")
            ->implode(' · ');

        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('label')->label('What this is')->required()->maxLength(80),

                    Forms\Components\Toggle::make('send_alert')
                        ->label('Show in their alerts')
                        ->helperText('Appears on the shop\'s Alerts list. Billing alerts are critical, so they reach every shop whether or not they have the alerts add-on.'),

                    Forms\Components\Toggle::make('send_email')
                        ->label('Email it')
                        ->helperText('Goes to their billing email, or the owner if none is set. Worth keeping on for anything urgent — people do not log in daily.'),

                    Forms\Components\TextInput::make('repeat_after_hours')
                        ->label('Do not repeat within (hours)')
                        ->numeric()->minValue(0)
                        ->helperText('Stops three retries becoming three emails. 0 sends every time, which is what a receipt wants.'),
                ])->columns(2),

            Forms\Components\Section::make('Wording')
                ->description($tokens)
                ->schema([
                    Forms\Components\TextInput::make('subject')->required()->maxLength(191),
                    Forms\Components\Textarea::make('body')->required()->rows(8),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Notice')->searchable(),
                Tables\Columns\TextColumn::make('event')->badge(),
                Tables\Columns\IconColumn::make('send_alert')->label('Alert')->boolean(),
                Tables\Columns\IconColumn::make('send_email')->label('Email')->boolean(),
                Tables\Columns\TextColumn::make('repeat_after_hours')->label('No repeat within')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state . ' h' : 'always sends'),
                Tables\Columns\TextColumn::make('subject')->limit(46)->color('gray'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    public static function canCreate(): bool { return false; }   // the code emits a fixed set of events

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillingNoticeTemplates::route('/'),
            'edit'  => Pages\EditBillingNoticeTemplate::route('/{record}/edit'),
        ];
    }
}
