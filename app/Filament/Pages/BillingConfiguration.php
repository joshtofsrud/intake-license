<?php

namespace App\Filament\Pages;

use App\Models\BillingSettings;
use App\Services\StripeBillingService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class BillingConfiguration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Billing configuration';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.billing-configuration';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = BillingSettings::current();
        $this->form->fill([
            'stripe_mode' => $settings->stripe_mode,
            'stripe_test_publishable_key' => $settings->stripe_test_publishable_key,
            'stripe_test_secret_key' => $settings->stripe_test_secret_key,
            'stripe_test_webhook_secret' => $settings->stripe_test_webhook_secret,
            'stripe_live_publishable_key' => $settings->stripe_live_publishable_key,
            'stripe_live_secret_key' => $settings->stripe_live_secret_key,
            'stripe_live_webhook_secret' => $settings->stripe_live_webhook_secret,
            'stripe_price_starter_monthly' => $settings->stripe_price_starter_monthly,
            'stripe_price_starter_annual' => $settings->stripe_price_starter_annual,
            'stripe_price_branded_monthly' => $settings->stripe_price_branded_monthly,
            'stripe_price_branded_annual' => $settings->stripe_price_branded_annual,
            'stripe_price_scale_monthly' => $settings->stripe_price_scale_monthly,
            'stripe_price_scale_annual' => $settings->stripe_price_scale_annual,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Mode')
                    ->description('Use test mode for development. Flip to live only after verifying everything works end-to-end.')
                    ->schema([
                        Select::make('stripe_mode')
                            ->label('Active mode')
                            ->options([
                                'test' => 'Test mode (no real charges)',
                                'live' => 'Live mode (real charges)',
                            ])
                            ->required()
                            ->native(false),
                    ]),

                Section::make('Test mode keys')
                    ->description('From Stripe dashboard → Developers → API keys (toggle "Test mode" on).')
                    ->collapsible()
                    ->schema([
                        TextInput::make('stripe_test_publishable_key')
                            ->label('Publishable key')
                            ->placeholder('pk_test_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('stripe_test_secret_key')
                            ->label('Secret key')
                            ->placeholder('sk_test_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('stripe_test_webhook_secret')
                            ->label('Webhook signing secret')
                            ->placeholder('whsec_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->helperText('Get this after creating a webhook endpoint in Stripe dashboard.'),
                    ]),

                Section::make('Live mode keys')
                    ->description('Only populate when you are ready to charge real cards. Leave blank during development.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('stripe_live_publishable_key')
                            ->label('Publishable key')
                            ->placeholder('pk_live_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('stripe_live_secret_key')
                            ->label('Secret key')
                            ->placeholder('sk_live_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('stripe_live_webhook_secret')
                            ->label('Webhook signing secret')
                            ->placeholder('whsec_...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                    ]),

                Section::make('Plan tier price IDs')
                    ->description('Create products + prices in Stripe dashboard, then paste the price IDs here. Prices are displayed only — they live in Stripe.')
                    ->collapsible()
                    ->schema([
                        TextInput::make('stripe_price_starter_monthly')
                            ->label('Starter — monthly ($29)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_starter_annual')
                            ->label('Starter — annual ($290, 2 mo free)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_branded_monthly')
                            ->label('Branded — monthly ($79)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_branded_annual')
                            ->label('Branded — annual ($790, 2 mo free)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_scale_monthly')
                            ->label('Scale — monthly ($199)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_scale_annual')
                            ->label('Scale — annual ($1990, 2 mo free)')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = BillingSettings::current();
        $settings->update($state);

        Notification::make()
            ->success()
            ->title('Billing configuration saved')
            ->body('Keys are encrypted at rest. Test the connection to verify.')
            ->send();
    }

    public function testConnection(): void
    {
        // Save before testing so the service reads fresh values
        $this->save();

        $result = app(StripeBillingService::class)->testConnection();

        $settings = BillingSettings::current();
        $settings->update([
            'last_verified_at' => now(),
            'last_verified_status' => $result['ok'] ? 'success' : 'failed',
            'last_verified_message' => $result['message'],
        ]);

        if ($result['ok']) {
            Notification::make()
                ->success()
                ->title('Connection successful')
                ->body($result['message'])
                ->duration(8000)
                ->send();
        } else {
            Notification::make()
                ->danger()
                ->title('Connection failed')
                ->body($result['message'])
                ->duration(10000)
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Save configuration')
                ->submit('save'),

            \Filament\Actions\Action::make('testConnection')
                ->label('Save and test connection')
                ->color('gray')
                ->icon('heroicon-o-signal')
                ->action('testConnection'),
        ];
    }
}
