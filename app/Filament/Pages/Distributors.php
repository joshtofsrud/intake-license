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
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'catalog';

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

    /**
     * MARKER-CODE-SOURCE — the distributor actually selected on screen.
     *
     * $code used to be maintained solely by the Select's afterStateUpdated
     * callback. When that didn't fire, the dropdown said BTI while every
     * action still ran against HLC — which is why the banner kept reading
     * "HLC connected" and why testing BTI returned 401: it was testing HLC's
     * key against BTI's endpoint.
     *
     * The selection already lives in $data['code']; reading it from there
     * makes the dropdown the source of truth. The property remains the
     * fallback for the first render, before a selection exists.
     */
    protected function currentCode(): string
    {
        $fromForm = strtoupper((string) ($this->data['code'] ?? ''));

        if ($fromForm !== '' && app(\App\Services\Distributors\DistributorRegistry::class)->isSupported($fromForm)) {
            $this->code = $fromForm;
        }

        return $this->code;
    }

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
        $conn = PlatformDistributorConnection::forCode($this->currentCode());

        // The stored credential is one string; BTI's is "username:password".
        // Split it back out so the shop-facing shape and this one agree.
        $user = '';
        $pass = '';
        if (strtoupper($this->code) === 'BTI' && str_contains((string) $conn->api_key, ':')) {
            [$user, $pass] = explode(':', (string) $conn->api_key, 2);
        }

        // MARKER-CLS-FIELD-ADMIN — QBP packs "api1:cls" in the same slot.
        // API1 is free and carries the catalog; CLS is licensed and carries
        // only the images.
        $apiKeyShown = (string) $conn->api_key;
        $clsKey      = '';
        if (strtoupper($this->code) === 'QBP' && str_contains($apiKeyShown, ':')) {
            [$apiKeyShown, $clsKey] = explode(':', $apiKeyShown, 2);
        }

        $this->form->fill([
            // MARKER-DIST-FORM-FILL — without this the selector rendered
            // "Select an option" and every field under it looked empty,
            // including a stored key and an active connection.
            'code'       => $this->code,
            'api_key'    => $apiKeyShown,
            'cls_key'    => $clsKey,
            'username'   => $user,
            'password'   => $pass,
            'region'     => $conn->region ?: 'us',
            'auth_style' => $conn->auth_style,
            'base_url'   => $conn->base_url,
            // Default to the stored value, not to false — a connection that
            // is live must never render as switched off.
            'is_active'  => (bool) $conn->is_active,
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
                        // MARKER-DIST-FORM-FILL — explicit spans. Two equal
                        // columns with six fields dropped in put the API key
                        // beside Auth style and stranded Region next to a
                        // toggle.
                        Select::make('code')->label('Distributor')
                            ->options($this->distributorOptions())
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                $this->code = (string) $state;
                                $this->loadConnection();
                            })
                            ->columnSpanFull(),

                        // One key, or a username and password — whichever this
                        // distributor actually issues.
                        TextInput::make('api_key')->label('Platform API key')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest. Leave blank to keep the saved key.')
                            ->visible(fn () => strtoupper($this->currentCode()) !== 'BTI')
                            ->columnSpanFull(),

                        // MARKER-CLS-FIELD-ADMIN — QBP's second key. Optional:
                        // without it the catalog, cost and stock all still
                        // work and only images stop.
                        TextInput::make('cls_key')->label('API3 key (Content License Service)')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Optional. Licensed separately by QBP and needed only for product images. Leave blank to keep the saved key.')
                            ->visible(fn () => strtoupper($this->currentCode()) === 'QBP')
                            ->columnSpanFull(),

                        TextInput::make('username')->label('Username')
                            ->autocomplete('off')
                            ->helperText('Your BTI account number.')
                            ->visible(fn () => strtoupper($this->currentCode()) === 'BTI'),

                        TextInput::make('password')->label('Password')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest. Leave blank to keep the saved one.')
                            ->visible(fn () => strtoupper($this->currentCode()) === 'BTI'),

                        Select::make('auth_style')->label('Auth style')->native(false)
                            ->options([
                                'authorization_apikey' => 'authorization_apikey (HLC)',
                                'x_api_key'             => 'x-api-key',
                                'bearer'                => 'bearer',
                            ])
                            ->visible(fn () => $this->usesAuthStyle($this->currentCode())),

                        TextInput::make('region')->label('Region')->maxLength(8)
                            ->placeholder('us'),

                        Toggle::make('is_active')->label('Is active')
                            ->columnSpanFull(),
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

        $conn = PlatformDistributorConnection::forCode($this->currentCode());

        $packed = $registry->packCredentials($this->currentCode(), $state);
        if ($packed !== null) {
            $conn->api_key = $packed;
        }

        $conn->region     = $state['region'] ?? 'us';
        $conn->base_url   = $state['base_url'] ?? $conn->base_url;
        $conn->is_active  = (bool) ($state['is_active'] ?? true);
        if ($this->usesAuthStyle($this->currentCode())) {
            $conn->auth_style = $state['auth_style'] ?? $conn->auth_style;
        }
        $conn->distributor_code = $this->currentCode();
        $conn->save();

        Notification::make()->success()
            ->title($this->currentCode() . ' connection saved')->send();

        // MARKER-QBP-CLS-AUTO — the image service prefix is per subscription
        // and fetched from CLS, not typed. Refresh it now so a shop never sits
        // with images it cannot display, which is what happened to Oakridge.
        if (strtoupper($this->currentCode()) === 'QBP') {
            try {
                \Illuminate\Support\Facades\Artisan::call('qbp:cls-refresh');
                Notification::make()->success()
                    ->title('QBP image service refreshed')
                    ->body('Product images should display for subscribed shops.')
                    ->send();
            } catch (\Throwable $e) {
                report($e);
                Notification::make()->warning()
                    ->title('Connection saved, image service not refreshed')
                    ->body('Run "php artisan qbp:cls-refresh" to retry — images stay hidden until it succeeds.')
                    ->send();
            }
        }
    }


    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save connection')->action('save'),

            // MARKER-TEST-CONNECTION-COPY — a 30s wait with no explanation reads
            // as a hung button. Confirming first turns it into an informed
            // choice, and matches runFull below.
            Action::make('test')->label('Test connection')->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Test ' . strtoupper($this->currentCode()) . ' connection')
                ->modalDescription(function () {
                    if (strtoupper($this->currentCode()) === 'BTI') {
                        return 'BTI has no status endpoint. The only authenticated URL rebuilds '
                             . 'their entire stock feed on every request, so this takes around 30 '
                             . 'seconds — roughly 25 of those are BTI generating the file before '
                             . 'it sends anything. Nothing is wrong; the button will sit spinning '
                             . 'until they answer.';
                    }

                    return 'Sends one authenticated request to ' . strtoupper($this->currentCode())
                         . ' to confirm the stored credentials work. Takes a second or two.';
                })
                ->modalSubmitActionLabel('Run test')
                ->action('testConnection'),

            Action::make('runFull')->label('Run full sync')->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Queues a full catalog pull from the selected distributor. Runs in the background.')
                ->action(fn () => $this->dispatchSync(false)),

            // MARKER-PAGE-FOLLOWS-CODE — BTI has no delta: the client always
            // downloads the whole feed and the --since watermark has nothing
            // to filter on. Offering the button would imply an incremental
            // pull that doesn't exist and quietly run a full one.
            Action::make('runDelta')->label('Run delta sync')->color('gray')
                ->visible(fn () => strtoupper($this->currentCode()) !== 'BTI')
                ->action(fn () => $this->dispatchSync(true)),
        ];
    }

    public function testConnection(): void
    {
        $conn = PlatformDistributorConnection::forCode($this->currentCode());

        // MARKER-TEST-PACKS-CREDS — persist what's on screen, packed the same
        // way save() does. This used to be $conn->update($form->getState()),
        // which for BTI wrote `username` and `password` (not columns) and no
        // api_key at all — so the test ran against the PREVIOUSLY stored
        // credential rather than the one just typed.
        $state = $this->form->getState();
        $packed = app(DistributorRegistry::class)->packCredentials($this->currentCode(), $state);
        if ($packed !== null) {
            $conn->api_key = $packed;
        }
        $conn->region = $state['region'] ?? ($conn->region ?: 'us');
        if ($this->usesAuthStyle($this->currentCode())) {
            $conn->auth_style = $state['auth_style'] ?? $conn->auth_style;
        }
        $conn->distributor_code = $this->currentCode();
        $conn->save();

        try {
            $adapter = app(DistributorRegistry::class)
                ->make($this->currentCode(), ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
            if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
                $adapter->setAuthStyle($conn->auth_style);
            }
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            // MARKER-QBP-TEST-SHAPE — show the adapter's own words. Every
            // adapter already returns a 'body' explaining what happened, and
            // this used to discard it for a bare status code — which is
            // useless when the request never completed and there is no code.
            $detail = trim((string) ($res['body'] ?? ''));
            if ($detail === '') {
                $detail = 'HTTP ' . ($res['status'] ?? '?');
            }

            $conn->update([
                'last_tested_at'    => now(),
                'last_test_status'  => $ok ? 'ok' : 'fail',
                'last_test_message' => mb_substr($detail, 0, 255),
            ]);

            $ok
                ? Notification::make()->success()
                    ->title('Connected to ' . $this->currentCode())->body($detail)->send()
                : Notification::make()->danger()
                    ->title('Connection failed')->body($detail)->persistent()->send();
        } catch (\Throwable $e) {
            $conn->update(['last_tested_at' => now(), 'last_test_status' => 'fail', 'last_test_message' => $e->getMessage()]);
            Notification::make()->danger()->title('Connection failed')->body($e->getMessage())->send();
        }
    }

    // MARKER-BRAND-SYNC — queue a single-brand refresh from the brand list.
    public function syncBrand(string $brand): void
    {
        $code = $this->currentCode();
        \App\Jobs\SyncDistributorBrandJob::dispatch($code, $brand);

        DB::table('distributor_brand_sync_status')
            ->where('distributor_code', $code)
            ->where('brand_name', $brand)
            ->update(['status' => 'syncing', 'updated_at' => now()]);

        \Filament\Notifications\Notification::make()
            ->title($brand . ' sync queued')
            ->success()->send();
    }

    protected function dispatchSync(bool $delta): void
    {
        // HLC8 running checkpoint — show progress immediately, before the
        // queued job picks up (closes the dispatch→start gap for the poller).
        DB::table('distributor_sync_state')->updateOrInsert(
            ['distributor_code' => $this->currentCode(), 'source_ref' => 'catalog'],
            ['last_status' => 'running', 'last_count' => 0, 'last_run_at' => now(),
             'last_error' => null, 'updated_at' => now(), 'created_at' => now()],
        );

        SyncDistributorCatalogJob::dispatch($this->currentCode(), $delta);
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
            ->where('distributor_code', $this->currentCode())->where('source_ref', 'catalog')->first();
        $conn = PlatformDistributorConnection::forCode($this->currentCode());

        return [
            'conn'   => $conn,
            'state'  => $state,
            'sampleOptions' => collect(self::SAMPLES)->map(fn ($s) => $s['label'])->all(),
            'brandStatuses' => DB::table('distributor_brand_sync_status')
                ->where('distributor_code', $this->currentCode())->orderBy('brand_name')->get(),
        ];
    }
}
