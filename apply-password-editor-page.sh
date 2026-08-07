#!/bin/bash
# apply-password-editor-page.sh
#
# Adds a dedicated "Password Editor" page to the master-admin sidebar
# (top-level, right under Tenants). Direct control: pick a tenant, see its
# owner, type a new password, click Set password. Applied immediately to the
# tenant's active owner login. Every change is written to the audit channel
# (tenant + owner email, never the password).
#
# This REPLACES the earlier row-action approach — do NOT run
# apply-tenant-owner-password-reset.sh; this is the version you asked for.
#
# Three pieces, mirroring the existing BillingConfiguration page exactly:
#   1. app/Filament/Pages/PasswordEditor.php               (new)
#   2. resources/views/filament/pages/password-editor.blade.php  (new)
#   3. registers the page in AdminPanelProvider ->pages([]) (this panel does
#      NOT auto-discover, so the page is invisible until listed)
#
# Idempotent: existing files are left alone; registration is added once.
# Run from the repo root on the Mac:  bash apply-password-editor-page.sh

set -e

if [ ! -d app/Filament/Pages ] || [ ! -f app/Providers/Filament/AdminPanelProvider.php ]; then
  echo "ERROR: run this from the intake-license repo root." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 1. The Page class
# ---------------------------------------------------------------------------
PAGE="app/Filament/Pages/PasswordEditor.php"
if [ -f "$PAGE" ]; then
  echo "SKIP $PAGE already exists"
else
cat > "$PAGE" <<'PHPEOF'
<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

class PasswordEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Password Editor';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.password-editor';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'tenant_id'    => null,
            'new_password' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Set a tenant owner password')
                    ->description('Pick a tenant, type a new password, and set it. The change applies immediately to that tenant\'s owner login.')
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Tenant')
                            ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live(),

                        Placeholder::make('owner')
                            ->label('Owner')
                            ->content(function (callable $get) {
                                $tid = $get('tenant_id');
                                if (! $tid) {
                                    return 'Select a tenant to see its owner.';
                                }
                                $owner = TenantUser::query()
                                    ->where('tenant_id', $tid)
                                    ->where('role', 'owner')
                                    ->where('is_active', true)
                                    ->first();

                                return $owner
                                    ? $owner->name . ' — ' . $owner->email
                                    : 'No active owner on this tenant.';
                            }),

                        TextInput::make('new_password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(10)
                            ->autocomplete('new-password')
                            ->helperText('At least 10 characters. Share it with the owner directly.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $owner = TenantUser::query()
            ->where('tenant_id', $state['tenant_id'])
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            Notification::make()
                ->danger()
                ->title('No active owner found for that tenant.')
                ->body('Nothing was changed.')
                ->send();
            return;
        }

        $owner->update(['password' => Hash::make($state['new_password'])]);

        debug_log()->audit(
            'tenant_password_reset',
            'Master admin set the owner password for ' . $owner->email,
            $owner,
            ['tenant_id' => $owner->tenant_id, 'owner_email' => $owner->email],
        );

        Notification::make()
            ->success()
            ->title('Password updated for ' . $owner->name)
            ->body('It is active now. Share it with them directly.')
            ->persistent()
            ->send();

        $this->form->fill([
            'tenant_id'    => $state['tenant_id'],
            'new_password' => '',
        ]);
    }
}
PHPEOF
  echo "OK   created $PAGE"
fi

# ---------------------------------------------------------------------------
# 2. The Blade view
# ---------------------------------------------------------------------------
VIEW="resources/views/filament/pages/password-editor.blade.php"
mkdir -p resources/views/filament/pages
if [ -f "$VIEW" ]; then
  echo "SKIP $VIEW already exists"
else
cat > "$VIEW" <<'BLADEEOF'
<x-filament-panels::page>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px;">
            <x-filament::button type="submit">
                Set password
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
BLADEEOF
  echo "OK   created $VIEW"
fi

# ---------------------------------------------------------------------------
# 3. Register the page in AdminPanelProvider ->pages([])
# ---------------------------------------------------------------------------
PROVIDER="app/Providers/Filament/AdminPanelProvider.php"
if grep -q 'Pages\\PasswordEditor::class' "$PROVIDER"; then
  echo "SKIP registration already present in $PROVIDER"
else
python3 - "$PROVIDER" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()

anchor = "                \\App\\Filament\\Pages\\BillingConfiguration::class,"
if src.count(anchor) != 1:
    print(f"ERROR: expected exactly 1 BillingConfiguration registration anchor, found {src.count(anchor)}.", file=sys.stderr)
    print("Add \\App\\Filament\\Pages\\PasswordEditor::class to the ->pages([]) array manually.", file=sys.stderr)
    sys.exit(1)

addition = "\n                \\App\\Filament\\Pages\\PasswordEditor::class,"
src = src.replace(anchor, anchor + addition)
open(path, "w").write(src)
print("OK   registered PasswordEditor in " + path)
PYEOF
fi

# ---------------------------------------------------------------------------
# 4. Syntax check
# ---------------------------------------------------------------------------
for f in "$PAGE" "$PROVIDER"; do
  if php -l "$f" >/dev/null 2>&1; then
    echo "OK   php -l clean: $f"
  else
    echo "ERROR: php -l failed on $f:" >&2
    php -l "$f" >&2 || true
    exit 1
  fi
done

echo ""
echo "SUCCESS: Password Editor added. After deploy, clear caches so Filament"
echo "picks up the new page + view (your deploy script likely does this):"
echo "    php artisan optimize:clear"
echo ""
echo "It appears in the master-admin sidebar as 'Password Editor', under Tenants."
