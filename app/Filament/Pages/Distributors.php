<?php
// MARKER-PATCH-HLC6

namespace App\Filament\Pages;

use App\Jobs\SyncDistributorCatalogJob;
use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorMapResolver;
use App\Services\Distributors\DistributorRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Master-admin distributor hub: connect (platform key), sync, and test the
 * field map live against real sample variants.
 */
class Distributors extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Distributors';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 10;

    protected static string $view = 'filament.pages.distributors';

    public ?array $data = [];
    public string $testSample = 'kenda';

    /**
     * MARKER-MASTER-DIST-PER-CODE — which distributor this page is acting on.
     * Every connection field, the test button and the sync button follow it.
     */
    public string $code = 'HLC';

    /** @return array<string,string> code => label, from the registry. */
    public function distributorOptions(): array
    {
        $registry = app(\App\Services\Distributors\DistributorRegistry::class);
        $out = [];
        foreach ($registry->supported() as $c) {
            $out[$c] = $registry->label($c);
        }
        return $out;
    }

    /** Does this distributor have a header-style choice to make? */
    private function usesAuthStyle(string $code): bool
    {
        // BTI is HTTP Basic; there is nothing to choose, and offering the
        // field would invite setting a value that does nothing.
        return strtoupper($code) !== 'BTI';
    }

    /** Real HLC variant shapes (trimmed) for the live mapping test. */
    public const SAMPLES = [
        'kenda' => [
            'label' => 'Kenda Kross Plus · 010845-01-26 (discontinued, UPC)',
            'product' => ['Name' => 'Kross Plus', 'BrandId' => 744, 'Brand' => 'Kenda', 'Taxable' => true,
                'Categories' => [['CategoryId' => 5637157329, 'CategoryName' => 'Hybrid Tires', 'Level' => 1], ['CategoryId' => 5637146712, 'CategoryName' => 'Tires', 'Level' => 0]]],
            'variant' => ['VariantNo' => '010845-01-26', 'StatusId' => 9, 'StatusDesc' => 'Disc-Supp', 'UPC' => '047853632057', 'EAN' => null,
                'Config' => '01', 'SizeId' => '26', 'MFGPartNumber' => '03990009', 'CanBeFulFilled' => true, 'UnitOfMesure' => 'EA', 'VariantId' => 5637171546,
                'GroundOnly' => false, 'HazmatType' => 'None', 'DateLastModified' => '2025-11-16T12:12:49',
                'CaseDimensions' => ['Quantity' => 50], 'Dimensions' => ['Weight' => 1.5],
                'Prices' => [['Amount' => 29.95, 'TypeId' => 3], ['Amount' => 29.95, 'TypeId' => 4], ['Amount' => 13.99, 'TypeId' => 0], ['Amount' => 5.99, 'TypeId' => 2]],
                'Attributes' => [['Name' => 'Bead', 'Value' => 'Wire'], ['Name' => 'TPI', 'Value' => '22TPI']]],
        ],
        'maxxis' => [
            'label' => 'Maxxis Holy Roller · 010873-01-20 (active, UPC null → EAN)',
            'product' => ['Name' => 'Holy Roller', 'BrandId' => 763, 'Brand' => 'Maxxis', 'Taxable' => true,
                'Categories' => [['CategoryId' => 5637157331, 'CategoryName' => 'BMX and Dirt Jump Tires', 'Level' => 1], ['CategoryId' => 5637146712, 'CategoryName' => 'Tires', 'Level' => 0]]],
            'variant' => ['VariantNo' => '010873-01-20', 'StatusId' => 7, 'StatusDesc' => 'Active', 'UPC' => null, 'EAN' => '4717784012308',
                'Config' => '01', 'SizeId' => '20', 'MFGPartNumber' => 'TB31020000', 'CanBeFulFilled' => true, 'UnitOfMesure' => 'EA', 'VariantId' => 5637171713,
                'GroundOnly' => false, 'HazmatType' => 'None', 'DateLastModified' => '2026-03-05T02:04:00',
                'CaseDimensions' => ['Quantity' => 100], 'Dimensions' => ['Weight' => 2.01],
                'Prices' => [['Amount' => 33, 'TypeId' => 3], ['Amount' => 36, 'TypeId' => 4], ['Amount' => 17.99, 'TypeId' => 0], ['Amount' => 17.45, 'TypeId' => 5]],
                'Attributes' => [['Name' => 'Bead', 'Value' => 'Wire'], ['Name' => 'TPI', 'Value' => '60TPI']]],
        ],
    ];

    public function mount(): void
    {
        $this->loadConnection();
    }

    /**
     * MARKER-MASTER-DIST-PER-CODE — refill the form from the selected
     * distributor's connection row. Called on mount and whenever the
     * selector changes, so switching distributor never shows another one's
     * stored values.
     */
    public function loadConnection(): void
    {
        $conn = PlatformDistributorConnection::forCode($this->code);

        // The stored credential is one string; BTI's is "username:password".
        // Split it back out so the shop-facing shape and this one agree.
        $user = '';
        $pass = '';
        if (strtoupper($this->code) === 'BTI' && str_contains((string) $conn->api_key, ':')) {
            [$user, $pass] = explode(':', (string) $conn->api_key, 2);
        }

        $this->form->fill([
            'api_key'    => $conn->api_key,
            'username'   => $user,
            'password'   => $pass,
            'region'     => $conn->region,
            'auth_style' => $conn->auth_style,
            'base_url'   => $conn->base_url,
            'is_active'  => $conn->is_active,
        ]);
    }

    public function updatedCode(): void
    {
        $this->loadConnection();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // MARKER-MASTER-DIST-PER-CODE
                Section::make('Platform connection')
                    ->description('The master-admin credentials that build the shared catalog (identity, MAP, MSRP). Tenants use their own for cost & availability.')
                    ->schema([
                        Select::make('code')->label('Distributor')
                            ->options($this->distributorOptions())
                            ->default('HLC')->native(false)
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                $this->code = (string) $state;
                                $this->loadConnection();
                            })
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        // One key, or a username and password — whichever this
                        // distributor actually issues.
                        TextInput::make('api_key')->label('Platform API key')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest.')
                            ->visible(fn () => strtoupper($this->code) !== 'BTI'),

                        TextInput::make('username')->label('Username')
                            ->autocomplete('off')
                            ->helperText('Your BTI account number.')
                            ->visible(fn () => strtoupper($this->code) === 'BTI'),

                        TextInput::make('password')->label('Password')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest.')
                            ->visible(fn () => strtoupper($this->code) === 'BTI'),

                        Select::make('auth_style')->native(false)
                            ->options([
                                'authorization_apikey' => 'authorization_apikey (HLC)',
                                'x_api_key'             => 'x-api-key',
                                'bearer'                => 'bearer',
                            ])->default('authorization_apikey')
                            ->visible(fn () => $this->usesAuthStyle($this->code)),

                        TextInput::make('region')->default('us')->maxLength(8),
                        Toggle::make('is_active')->default(true),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * MARKER-MASTER-DIST-PER-CODE — saves the selected distributor's row.
     *
     * Credentials are packed through the registry so a stored value means
     * the same thing here as on the tenant page: BTI's username and password
     * join with a colon into the single api_key that every adapter and
     * DistributorRegistry::make() already expect.
     */
    public function save(): void
    {
        $state = $this->form->getState();
        $registry = app(\App\Services\Distributors\DistributorRegistry::class);

        $conn = PlatformDistributorConnection::forCode($this->code);

        $packed = $registry->packCredentials($this->code, $state);
        if ($packed !== null) {
            $conn->api_key = $packed;
        }

        $conn->region     = $state['region'] ?? 'us';
        $conn->base_url   = $state['base_url'] ?? $conn->base_url;
        $conn->is_active  = (bool) ($state['is_active'] ?? true);
        if ($this->usesAuthStyle($this->code)) {
            $conn->auth_style = $state['auth_style'] ?? $conn->auth_style;
        }
        $conn->distributor_code = $this->code;
        $conn->save();

        Notification::make()->success()
            ->title($this->code . ' connection saved')->send();
    }


    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save connection')->action('save'),

            Action::make('test')->label('Test connection')->color('gray')->action('testConnection'),

            Action::make('runFull')->label('Run full sync')->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Queues a full catalog pull from the selected distributor. Runs in the background.')
                ->action(fn () => $this->dispatchSync(false)),

            Action::make('runDelta')->label('Run delta sync')->color('gray')
                ->action(fn () => $this->dispatchSync(true)),
        ];
    }

    public function testConnection(): void
    {
        $conn = PlatformDistributorConnection::forCode($this->code);
        // persist current form first so we test what's on screen
        $conn->update($this->form->getState());

        try {
            $adapter = app(DistributorRegistry::class)
                ->make($this->code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
            if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
                $adapter->setAuthStyle($conn->auth_style);
            }
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            $conn->update([
                'last_tested_at' => now(),
                'last_test_status' => $ok ? 'ok' : 'fail',
                'last_test_message' => $ok ? 'Connected' : ('HTTP ' . ($res['status'] ?? '?')),
            ]);

            $ok
                ? Notification::make()->success()->title('Connected to ' . $this->code)->send()
                : Notification::make()->danger()->title('Connection failed')->body('HTTP ' . ($res['status'] ?? '?'))->send();
        } catch (\Throwable $e) {
            $conn->update(['last_tested_at' => now(), 'last_test_status' => 'fail', 'last_test_message' => $e->getMessage()]);
            Notification::make()->danger()->title('Connection failed')->body($e->getMessage())->send();
        }
    }

    protected function dispatchSync(bool $delta): void
    {
        // HLC8 running checkpoint — show progress immediately, before the
        // queued job picks up (closes the dispatch→start gap for the poller).
        DB::table('distributor_sync_state')->updateOrInsert(
            ['distributor_code' => $this->code, 'source_ref' => 'catalog'],
            ['last_status' => 'running', 'last_count' => 0, 'last_run_at' => now(),
             'last_error' => null, 'updated_at' => now(), 'created_at' => now()],
        );

        SyncDistributorCatalogJob::dispatch($this->code, $delta);
        Notification::make()->success()
            ->title(($delta ? 'Delta' : 'Full') . ' sync queued')
            ->body('Running in the background. Refresh in a bit for updated stats.')
            ->send();
    }

    /** Resolve the selected sample through the LIVE field map for the test panel. */
    public function resolvedSample(): array
    {
        $s = self::SAMPLES[$this->testSample] ?? self::SAMPLES['kenda'];
        $canonical = app(DistributorMapResolver::class)->resolve('HLC', $s['variant'], $s['product']);
        return ['sample' => $s, 'canonical' => $canonical];
    }

    public function getViewData(): array
    {
        $state = DB::table('distributor_sync_state')
            ->where('distributor_code', $this->code)->where('source_ref', 'catalog')->first();
        $conn = PlatformDistributorConnection::forCode($this->code);

        return [
            'conn'   => $conn,
            'state'  => $state,
            'sampleOptions' => collect(self::SAMPLES)->map(fn ($s) => $s['label'])->all(),
            'brandStatuses' => DB::table('distributor_brand_sync_status')
                ->where('distributor_code', $this->code)->orderBy('brand_name')->get(),
        ];
    }
}
