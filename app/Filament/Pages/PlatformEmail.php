<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-PLATFORM-MAIL — master-admin control of the platform email sender.
 *
 * Applies to platform mail (signups, admin notices) that does not set its
 * own From. Tenant mail continues to use each tenant's configured sender.
 */
class PlatformEmail extends Page implements HasForms
{
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'config';

    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-at-symbol';
    protected static ?string $navigationLabel = 'Platform email';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 21;

    protected static string $view = 'filament.pages.platform-email';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = PlatformSettings::current();

        $this->form->fill([
            'mail_from_address' => $settings->mail_from_address,
            'mail_from_name'    => $settings->mail_from_name,
            // MARKER-MARKETING-ADMIN
            'email_broadcast_stream' => $settings->email_broadcast_stream,
            'email_rate'             => $settings->email_rate !== null ? (string) $settings->email_rate : '0.002',
            'test_recipient'    => auth()->user()?->email,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Platform sender')
                    ->description('Used for signup and platform emails that do not set their own sender. Tenant email keeps using each tenant\'s own configured sender.')
                    ->schema([
                        TextInput::make('mail_from_address')
                            ->label('From address')
                            ->email()
                            ->placeholder('hello@intake.works')
                            ->helperText('Must be a verified sender signature (or on a verified domain) in Postmark, or delivery will fail.')
                            ->autocomplete('off'),
                        TextInput::make('mail_from_name')
                            ->label('From name')
                            ->placeholder('Intake')
                            ->maxLength(120)
                            ->autocomplete('off'),
                    ]),

                // MARKER-MARKETING-ADMIN — the campaign sending switch + rate.
                Section::make('Marketing email')
                    ->description('Campaign sending for every tenant hinges on the stream below. Empty: campaign sending is OFF platform-wide — shops can draft and queue, nothing goes out, transactional mail is unaffected. Set: the worker drains queues onto that Postmark stream within a minute.')
                    ->schema([
                        TextInput::make('email_broadcast_stream')
                            ->label('Postmark broadcast stream ID')
                            ->placeholder('broadcast')
                            ->maxLength(64)
                            ->helperText('Create a Broadcasts-type stream in Postmark first, then put its ID here. Marketing must never ride the transactional stream — one spam complaint there can suspend receipts for every shop.')
                            ->autocomplete('off'),
                        TextInput::make('email_rate')
                            ->label('Rate per email (USD)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step('0.0001')
                            ->helperText('Stamped onto each ledger row at send time. Changing it applies from the next send on — rows already written keep the rate they were charged at.')
                            ->autocomplete('off'),
                    ]),

                Section::make('Send a test')
                    ->description('Proves the sender end to end without waiting for a real signup.')
                    ->schema([
                        TextInput::make('test_recipient')
                            ->label('Send test to')
                            ->email()
                            ->autocomplete('off'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        PlatformSettings::current()->update([
            'mail_from_address' => trim((string) ($state['mail_from_address'] ?? '')) ?: null,
            'mail_from_name'    => trim((string) ($state['mail_from_name'] ?? '')) ?: null,
            // MARKER-MARKETING-ADMIN
            'email_broadcast_stream' => trim((string) ($state['email_broadcast_stream'] ?? '')) ?: null,
            'email_rate'             => is_numeric($state['email_rate'] ?? null) ? (float) $state['email_rate'] : 0.002,
        ]);
        PlatformSettings::forget();

        Notification::make()
            ->success()
            ->title('Platform sender saved')
            ->body('Applies to the next email sent. Send a test to confirm Postmark accepts it.')
            ->send();
    }

    public function sendTest(): void
    {
        $this->save();

        $state = $this->form->getState();
        $to    = trim((string) ($state['test_recipient'] ?? ''));

        if ($to === '') {
            Notification::make()->warning()->title('Enter a recipient first')->send();
            return;
        }

        try {
            Mail::raw(
                "This is a test from Intake master admin.\n\n"
                . 'Sender: ' . (PlatformSettings::fromAddress() ?? '(none configured)') . "\n"
                . 'Sent: ' . now()->toDayDateTimeString(),
                fn ($m) => $m->to($to)->subject('Intake — platform sender test')
            );

            Notification::make()
                ->success()
                ->title('Test sent to ' . $to)
                ->body('Check the From line. If it never arrives, the address is probably not verified in Postmark.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Test failed')
                ->body($e->getMessage())
                ->send();
        }
    }
}
