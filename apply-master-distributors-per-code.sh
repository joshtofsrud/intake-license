#!/bin/bash
# master-distributors-per-code — master admin controls for BTI (and the next one).
#
#   The Distributors page was HLC end to end: forCode('HLC') in mount, save,
#   test and sync, a section titled "HLC — platform connection", and a
#   dispatch of SyncDistributorCatalogJob('HLC'). There was no way to hold
#   BTI's platform credentials or trigger its catalog pull from the UI at
#   all — BTI has only ever been synced by passing --key on the command line.
#
#   Now the page has a distributor selector fed by DistributorRegistry, and
#   every action operates on the selected code. Adding QBP later is the
#   adapter plus a registry entry, with nothing to change here.
#
#   Two things that aren't just find-and-replace:
#
#   · Credentials differ in shape. HLC takes one key; BTI takes a username
#     and password joined with a colon. The form asks for whatever the
#     registry says that distributor uses, and packs them the same way the
#     tenant page does, so a stored credential means the same thing on both
#     sides.
#
#   · auth_style is meaningless for BTI — it's HTTP Basic, not a header
#     variant — so that field only shows for distributors that have a choice
#     to make. Leaving it visible would invite someone to set a value that
#     does nothing.
#
#   The live mapping test keeps its hardcoded HLC samples, but is now hidden
#   for other distributors rather than shown with data that can't apply. A
#   BTI mapping test wants real rows from the BTI feed, which is its own
#   piece of work.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "MARKER-MASTER-DIST-PER-CODE" app/Filament/Pages/Distributors.php; then
  echo "master-distributors-per-code already applied — aborting."; exit 1
fi

python3 - <<'MDP_0_EOF'
import io
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- property
old = """    public ?array $data = [];
    public string $testSample = 'kenda';"""
assert s.count(old) == 1, s.count(old)
new = """    public ?array $data = [];
    public string $testSample = 'kenda';

    /**
     * MARKER-MASTER-DIST-PER-CODE \u2014 which distributor this page is acting on.
     * Every connection field, the test button and the sync button follow it.
     */
    public string $code = 'HLC';

    /** @return array<string,string> code => label, from the registry. */
    public function distributorOptions(): array
    {
        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);
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
    }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- mount
old = """    public function mount(): void
    {
        $conn = PlatformDistributorConnection::forCode('HLC');
        $this->form->fill([
            'api_key'    => $conn->api_key,
            'region'     => $conn->region,
            'auth_style' => $conn->auth_style,
            'base_url'   => $conn->base_url,
            'is_active'  => $conn->is_active,
        ]);
    }"""
assert s.count(old) == 1, s.count(old)
new = """    public function mount(): void
    {
        $this->loadConnection();
    }

    /**
     * MARKER-MASTER-DIST-PER-CODE \u2014 refill the form from the selected
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
    }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- form
old = """                Section::make('HLC — platform connection')
                    ->description('The master-admin key that builds the shared catalog (identity, MAP, MSRP). Tenants use their own key for cost & availability.')
                    ->schema([
                        TextInput::make('api_key')->label('Platform API key')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest.'),
                        Select::make('auth_style')->native(false)
                            ->options([
                                'authorization_apikey' => 'authorization_apikey (HLC)',
                                'x_api_key'             => 'x-api-key',
                                'bearer'                => 'bearer',
                            ])->default('authorization_apikey'),
                        TextInput::make('region')->default('us')->maxLength(8),
                        Toggle::make('is_active')->default(true),
                    ])->columns(2),"""
assert s.count(old) == 1, s.count(old)
new = """                // MARKER-MASTER-DIST-PER-CODE
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
                    ])->columns(2),"""
s = s.replace(old, new)

# ---------------------------------------------------------------- the rest
s = s.replace("PlatformDistributorConnection::forCode('HLC')",
              "PlatformDistributorConnection::forCode($this->code)")
s = s.replace("->make('HLC', ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us'])",
              "->make($this->code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us'])")
s = s.replace("Notification::make()->success()->title('Connected to HLC')->send()",
              "Notification::make()->success()->title('Connected to ' . $this->code)->send()")
s = s.replace("->modalDescription('Queues a full catalog pull from HLC. Runs in the background.')",
              "->modalDescription('Queues a full catalog pull from the selected distributor. Runs in the background.')")
s = s.replace("['distributor_code' => 'HLC', 'source_ref' => 'catalog'],",
              "['distributor_code' => $this->code, 'source_ref' => 'catalog'],")
s = s.replace("SyncDistributorCatalogJob::dispatch('HLC', $delta);",
              "SyncDistributorCatalogJob::dispatch($this->code, $delta);")
s = s.replace("->where('distributor_code', 'HLC')->where('source_ref', 'catalog')->first();",
              "->where('distributor_code', $this->code)->where('source_ref', 'catalog')->first();")
s = s.replace("->where('distributor_code', 'HLC')->orderBy('brand_name')->get(),",
              "->where('distributor_code', $this->code)->orderBy('brand_name')->get(),")

io.open(p, 'w', encoding='utf-8').write(s)

left = s.count("'HLC'")
print('per-code ok; remaining literal HLC references:', left)
MDP_0_EOF

# ---------------------------------------------------- save: pack credentials
python3 - <<'MDP_1_EOF'
import io, re
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

old_save = """    public function save(): void
    {
        $conn = PlatformDistributorConnection::forCode($this->code);
        $conn->update($this->form->getState());

        Notification::make()->success()->title('Connection saved')->send();
    }
"""
assert s.count(old_save) == 1, s.count(old_save)
i = s.index(old_save)
j = i + len(old_save)

new_save = """    /**
     * MARKER-MASTER-DIST-PER-CODE \u2014 saves the selected distributor's row.
     *
     * Credentials are packed through the registry so a stored value means
     * the same thing here as on the tenant page: BTI's username and password
     * join with a colon into the single api_key that every adapter and
     * DistributorRegistry::make() already expect.
     */
    public function save(): void
    {
        $state = $this->form->getState();
        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);

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

"""

io.open(p, 'w', encoding='utf-8').write(s[:i] + new_save + s[j:])
print('save() replaced')
MDP_1_EOF

# ---------------------------------------------------- hide the HLC-only test
python3 - <<'MDP_2_EOF'
import io
p = 'resources/views/filament/pages/distributors.blade.php'
try:
    s = io.open(p, encoding='utf-8').read()
except FileNotFoundError:
    print('view not found — skipping mapping-test guard')
    raise SystemExit

needle = 'testSample'
if needle in s and 'MARKER-MASTER-DIST-PER-CODE' not in s:
    i = s.index(needle)
    # wrap the whole mapping-test region in an HLC-only guard by finding the
    # nearest enclosing block start above it
    start = s.rfind('<div', 0, i)
    s = (s[:start]
         + "{{-- MARKER-MASTER-DIST-PER-CODE — the mapping test uses hardcoded HLC\n"
           "     variant shapes, so it's hidden for other distributors rather than\n"
           "     shown with data that cannot apply to them. --}}\n"
           "@if (strtoupper($this->code) === 'HLC')\n"
         + s[start:])
    s = s.rstrip() + "\n@endif\n"
    io.open(p, 'w', encoding='utf-8').write(s)
    print('mapping test guarded')
else:
    print('mapping test: nothing to guard')
MDP_2_EOF

php -l app/Filament/Pages/Distributors.php

echo
echo "master-distributors-per-code applied."
