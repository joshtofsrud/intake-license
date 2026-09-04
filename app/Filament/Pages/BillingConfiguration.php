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
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'config';

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Billing configuration';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?int $navigationSort = 40;

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
            'stripe_test_contrib_webhook_secret' => $settings->stripe_test_contrib_webhook_secret,   // MARKER-CONTRIBUTIONS
            'stripe_live_publishable_key' => $settings->stripe_live_publishable_key,
            'stripe_live_secret_key' => $settings->stripe_live_secret_key,
            'stripe_live_webhook_secret' => $settings->stripe_live_webhook_secret,
            'stripe_live_contrib_webhook_secret' => $settings->stripe_live_contrib_webhook_secret,
            // MARKER-PRICING-ONE-PLACE
            'plan_prices' => collect(\App\Support\PlanPricing::all())->map(fn ($cents, $tier) => [
                'tier'           => $tier,
                'dollars'        => number_format($cents / 100, 2, '.', ''),
                'effective_from' => now()->toDateString(),
            ])->values()->all(),
            'stripe_price_starter_monthly' => $settings->stripe_price_starter_monthly,
            'stripe_price_starter_annual' => $settings->stripe_price_starter_annual,
            'stripe_price_branded_monthly' => $settings->stripe_price_branded_monthly,
            'stripe_price_branded_annual' => $settings->stripe_price_branded_annual,
            'stripe_price_scale_monthly' => $settings->stripe_price_scale_monthly,
            'stripe_price_scale_annual' => $settings->stripe_price_scale_annual,
            // MARKER-TENANT-STANDING-ADMIN
            'past_due_grace_days' => $settings->past_due_grace_days ?? 14,
            'past_due_action'     => $settings->past_due_action ?? 'lock',
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
                        TextInput::make('stripe_test_contrib_webhook_secret')
                            ->label('Contributions webhook signing secret')
                            ->helperText('From the /webhooks/stripe/contributions endpoint — a different secret to the one above.')
                            ->password()
                            ->revealable(),
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

                // MARKER-PRICING-ONE-PLACE — the list price, edited here rather
                // than on a page of its own, so it sits with the Stripe IDs it
                // labels and there is one place to change a number.
                Section::make('Plan prices')
                    ->description('What each plan costs per licensed location, per month. This is the list price — what a new shop is quoted and what the marketing page should say. It is not what anyone is charged: Stripe decides that from their subscription, and a shop\'s real cost is on their statement with any discount applied.')
                    ->collapsible()
                    ->schema([
                        \Filament\Forms\Components\Repeater::make('plan_prices')
                            ->label(false)
                            ->schema([
                                TextInput::make('tier')->label('Plan')->disabled()->dehydrated(),
                                TextInput::make('dollars')->label('Per month')->numeric()->prefix('$')->required(),
                                \Filament\Forms\Components\DatePicker::make('effective_from')
                                    ->label('From')
                                    ->helperText('Today applies it now; a future date waits.'),
                            ])
                            ->columns(3)
                            ->addable(false)->deletable(false)->reorderable(false),

                        \Filament\Forms\Components\Placeholder::make('scheduled')
                            ->label('Scheduled changes')
                            ->content(function () {
                                $rows = \App\Support\PlanPricing::scheduled();
                                if ($rows->isEmpty()) {
                                    return 'None. A price with a future date would be listed here until it takes over.';
                                }
                                return $rows->map(fn ($r) =>
                                    ucfirst($r->tier) . ' → ' . $r->dollars() . ' on ' . $r->effective_from->format('M j, Y')
                                )->implode(' · ');
                            }),
                    ]),

                Section::make('Plan tier price IDs')
                    ->description('Create the products and prices in Stripe, then paste the IDs here. The amounts shown come from the section above; Stripe must be kept in step by hand — changing a price here does not change what Stripe charges.')
                    ->collapsible()
                    ->schema([
                        TextInput::make('stripe_price_starter_monthly')
                            ->label(fn () => 'Starter — monthly ($' . number_format(\App\Support\PlanPricing::for('starter') / 100, 0) . ')')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_starter_annual')
                            ->label('Starter — annual')
                            ->helperText('Annual pricing is set in Stripe only — the table above is monthly.')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_branded_monthly')
                            ->label(fn () => 'Branded — monthly ($' . number_format(\App\Support\PlanPricing::for('branded') / 100, 0) . ')')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_branded_annual')
                            ->label('Branded — annual')
                            ->helperText('Annual pricing is set in Stripe only — the table above is monthly.')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_scale_monthly')
                            ->label(fn () => 'Scale — monthly ($' . number_format(\App\Support\PlanPricing::for('scale') / 100, 0) . ')')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                        TextInput::make('stripe_price_scale_annual')
                            ->label('Scale — annual')
                            ->helperText('Annual pricing is set in Stripe only — the table above is monthly.')
                            ->placeholder('price_...')
                            ->autocomplete('off'),
                    ]),

                // MARKER-TENANT-STANDING-ADMIN
                Section::make('Past-due handling')
                    ->description('What happens when a shop\'s card fails. Grace is counted from the first failed invoice and clears the moment a payment succeeds.')
                    ->schema([
                        TextInput::make('past_due_grace_days')
                            ->label('Grace period (days)')
                            ->numeric()->minValue(0)->maxValue(120)->required()
                            ->helperText('Stripe retries its own schedule inside this window. 0 locks on the first failure.'),
                        Select::make('past_due_action')
                            ->label('When grace runs out')
                            ->options([
                                'lock'     => 'Lock staff out of Intake',
                                'readonly' => 'Read-only — they can look, not sell',
                            ])
                            ->required()->native(false),
                        Placeholder::make('past_due_legend')
                            ->label('What the shop actually experiences')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div style="font-size:12.5px;line-height:1.7">'
                                . '<b>Day 0 — payment fails.</b> A quiet banner appears in their admin. Nothing else changes.<br>'
                                . '<b>During grace.</b> Full access. The banner sharpens inside the last three days and names the date.<br>'
                                . '<b>After grace.</b> Staff hit a lock screen (or read-only, above) until they pay.<br>'
                                . '<b style="color:#BEF264">Never affected, in any state:</b> their public booking page, '
                                . 'customer accounts, and gift card balance checks. A shop that owes us money still has '
                                . 'customers who don\'t.</div>'
                            )),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // MARKER-PRICING-ONE-PLACE save — a row per tier, dated. Writing a row
        // rather than updating one keeps the history: a price that applied last
        // month stays in the table, which is what makes a scheduled change and
        // a later audit both possible.
        foreach (($this->form->getState()['plan_prices'] ?? []) as $row) {
            $tier = (string) ($row['tier'] ?? '');
            if ($tier === '' || ! isset($row['dollars'])) continue;

            $cents = (int) round(((float) $row['dollars']) * 100);
            $from  = $row['effective_from'] ?: now()->toDateString();

            if (\App\Support\PlanPricing::for($tier) === $cents
                && \Carbon\Carbon::parse($from)->isToday()) {
                continue;   // unchanged: do not litter the table with duplicates
            }

            \App\Models\PlanPrice::updateOrCreate(
                ['tier' => $tier, 'effective_from' => $from],
                ['price_cents' => $cents, 'created_by' => \Illuminate\Support\Facades\Auth::guard('web')->user()?->email],
            );
        }
        \App\Support\PlanPricing::forget();

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
