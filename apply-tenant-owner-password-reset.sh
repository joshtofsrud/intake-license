#!/bin/bash
# apply-tenant-owner-password-reset.sh
#
# Adds a "Reset password" row action to the master-admin Filament
# TenantResource (app/Filament/Resources/TenantResource.php), beside
# Impersonate/Suspend. It lets a master admin set a new password for a
# tenant's OWNER when they're locked out.
#
# Behavior:
#   - Opens a modal showing which owner will be reset (name + email).
#   - Pre-fills a freshly generated 14-char password (admin can edit it).
#   - On submit: finds the tenant's active owner (role=owner, is_active),
#     sets Hash::make(password) on their TenantUser (password is fillable,
#     single-hash — matches how tenant login verifies), and shows the new
#     password ONCE in a persistent success notification to relay.
#   - No active owner -> danger notification, no change.
#   - Writes an audit row via debug_log()->audit(...) — the tenant + owner
#     email are logged, never the password.
#
# This lives inside the Filament admin panel, which is gated by
# User::canAccessPanel (is_admin / ADMIN_EMAIL). It does NOT add any
# hand-rolled route.
#
# Idempotent: re-running is a no-op once the reset_password action exists.
# Run from the repo root on the Mac:  bash apply-tenant-owner-password-reset.sh

set -e

FILE="app/Filament/Resources/TenantResource.php"

if [ ! -f "$FILE" ]; then
  echo "ERROR: $FILE not found — run this from the intake-license repo root." >&2
  exit 1
fi

if grep -q "make('reset_password')" "$FILE"; then
  echo "OK   already patched — reset_password action present. No change."
  exit 0
fi

python3 - "$FILE" <<'PYEOF'
import sys

path = sys.argv[1]
src = open(path).read()

# Anchor: the last line of the existing impersonate action. We insert the new
# action immediately after it, inside the ->actions([...]) array.
anchor = "                    ->action(fn (Tenant $t) => redirect()->route('admin.impersonate', $t->id)),"

if src.count(anchor) != 1:
    print(f"ERROR: expected exactly 1 impersonate-action anchor, found {src.count(anchor)}.", file=sys.stderr)
    print("TenantResource differs from what this patch expects — inspect the actions() block before editing.", file=sys.stderr)
    sys.exit(1)

new_action = r'''

                Tables\Actions\Action::make('reset_password')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->modalHeading('Reset owner password')
                    ->modalDescription('Sets a new password for this tenant\'s owner. Share it with them directly — it is shown only once.')
                    ->modalSubmitActionLabel('Set password')
                    ->fillForm(fn (Tenant $t) => [
                        'new_password' => \Illuminate\Support\Str::random(14),
                    ])
                    ->form([
                        Forms\Components\Placeholder::make('owner_target')
                            ->label('Owner')
                            ->content(function (Tenant $t) {
                                $o = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                                    ->where('role', 'owner')->where('is_active', true)->first();
                                return $o ? ($o->name . ' — ' . $o->email) : 'No active owner on this tenant.';
                            }),
                        Forms\Components\TextInput::make('new_password')
                            ->label('New password')
                            ->required()
                            ->minLength(10)
                            ->helperText('Auto-generated. Edit if you like, then Set password. Copy it before closing.'),
                    ])
                    ->action(function (Tenant $t, array $data) {
                        $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                            ->where('role', 'owner')->where('is_active', true)->first();

                        if (! $owner) {
                            \Filament\Notifications\Notification::make()
                                ->title('No active owner found for this tenant.')
                                ->danger()->send();
                            return;
                        }

                        $owner->update([
                            'password' => \Illuminate\Support\Facades\Hash::make($data['new_password']),
                        ]);

                        debug_log()->audit(
                            'tenant_password_reset',
                            'Master admin reset the owner password for ' . $t->name,
                            $owner,
                            ['tenant_id' => $t->id, 'owner_email' => $owner->email],
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Password updated for ' . $owner->name)
                            ->body('New password: ' . $data['new_password'] . "\nCopy it now — it will not be shown again.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),'''

src = src.replace(anchor, anchor + new_action)
open(path, "w").write(src)
print("OK   inserted reset_password action into " + path)
PYEOF

# ---- verify PHP syntax ----
if php -l "$FILE" >/dev/null 2>&1; then
  echo "OK   php -l: no syntax errors"
else
  echo "ERROR: php -l reported a syntax error after patching:" >&2
  php -l "$FILE" >&2 || true
  exit 1
fi

echo ""
echo "Actions now defined in TenantResource:"
grep -nE "Action::make\('(view_site|impersonate|reset_password|suspend)'\)" "$FILE"
echo ""
echo "SUCCESS: owner password-reset action added. Review with: git diff $FILE"
