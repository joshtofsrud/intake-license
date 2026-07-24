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
