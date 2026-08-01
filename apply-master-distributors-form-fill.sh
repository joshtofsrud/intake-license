#!/bin/bash
# master-distributors-form-fill — the selector was blank, so the form was too.
#
#   apply-master-distributors-per-code added a Distributor select but never
#   put `code` into the form fill, so the field rendered "Select an option"
#   and every value under it looked empty — including HLC's stored key and
#   its Is active toggle.
#
#   Nothing was actually lost: save() skips a blank credential, and the key
#   is intact (39 chars, active). It only ever looked wiped. But it looked
#   wiped on the screen that holds the platform credentials, which is bad
#   enough on its own — and had anyone pressed Save while the toggle read
#   off, is_active WOULD have been written false.
#
#   So two changes:
#     · `code` is filled on mount and on every reload, so the selector always
#       shows which distributor is being edited
#     · is_active defaults to the stored value rather than to false, and the
#       credential fields keep their placeholder when a key exists, so a
#       populated connection can never read as an empty one
#
#   Layout: the section was two equal columns with six fields dropped into
#   it, which put the API key beside Auth style and left Region orphaned
#   against a toggle. Spans are explicit now — the distributor and the
#   credential each take the full width, the smaller settings pair up.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "MARKER-DIST-FORM-FILL" app/Filament/Pages/Distributors.php; then
  echo "master-distributors-form-fill already applied — aborting."; exit 1
fi

python3 - <<'MFF_0_EOF'
import io
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- fill code
old = """        $this->form->fill([
            'api_key'    => $conn->api_key,
            'username'   => $user,
            'password'   => $pass,
            'region'     => $conn->region,
            'auth_style' => $conn->auth_style,
            'base_url'   => $conn->base_url,
            'is_active'  => $conn->is_active,
        ]);"""
assert s.count(old) == 1, s.count(old)
new = """        $this->form->fill([
            // MARKER-DIST-FORM-FILL \u2014 without this the selector rendered
            // "Select an option" and every field under it looked empty,
            // including a stored key and an active connection.
            'code'       => $this->code,
            'api_key'    => $conn->api_key,
            'username'   => $user,
            'password'   => $pass,
            'region'     => $conn->region ?: 'us',
            'auth_style' => $conn->auth_style,
            'base_url'   => $conn->base_url,
            // Default to the stored value, not to false \u2014 a connection that
            // is live must never render as switched off.
            'is_active'  => (bool) $conn->is_active,
        ]);"""
s = s.replace(old, new)

# ---------------------------------------------------------------- layout
old = """                        Select::make('code')->label('Distributor')
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
                        Toggle::make('is_active')->default(true),"""
assert s.count(old) == 1, s.count(old)
new = """                        // MARKER-DIST-FORM-FILL \u2014 explicit spans. Two equal
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
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        // One key, or a username and password — whichever this
                        // distributor actually issues.
                        TextInput::make('api_key')->label('Platform API key')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest. Leave blank to keep the saved key.')
                            ->visible(fn () => strtoupper($this->code) !== 'BTI')
                            ->columnSpanFull(),

                        TextInput::make('username')->label('Username')
                            ->autocomplete('off')
                            ->helperText('Your BTI account number.')
                            ->visible(fn () => strtoupper($this->code) === 'BTI'),

                        TextInput::make('password')->label('Password')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Encrypted at rest. Leave blank to keep the saved one.')
                            ->visible(fn () => strtoupper($this->code) === 'BTI'),

                        Select::make('auth_style')->label('Auth style')->native(false)
                            ->options([
                                'authorization_apikey' => 'authorization_apikey (HLC)',
                                'x_api_key'             => 'x-api-key',
                                'bearer'                => 'bearer',
                            ])
                            ->visible(fn () => $this->usesAuthStyle($this->code)),

                        TextInput::make('region')->label('Region')->maxLength(8)
                            ->placeholder('us'),

                        Toggle::make('is_active')->label('Is active')
                            ->columnSpanFull(),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('form fill + layout ok')
MFF_0_EOF

echo
echo "master-distributors-form-fill applied."
