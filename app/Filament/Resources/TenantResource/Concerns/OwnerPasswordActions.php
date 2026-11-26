<?php

namespace App\Filament\Resources\TenantResource\Concerns;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * MARKER-OWNER-PASSWORD: the owner's password tools, on the Tenants card menu
 * and the Edit Tenant header. They used to be table row actions, but the
 * Tenants page draws its own cards and never showed the table, so neither
 * could be reached. The tenant comes from the card's argument, or the record
 * being edited.
 */
trait OwnerPasswordActions
{
    protected function ownerPwTenant(array $arguments): ?Tenant
    {
        if (! empty($arguments['tenant'])) return Tenant::find($arguments['tenant']);
        return property_exists($this, 'record') && $this->record instanceof Tenant ? $this->record : null;
    }

    protected static function ownerOf(?Tenant $t): ?TenantUser
    {
        return $t ? TenantUser::where('tenant_id', $t->id)->where('role', 'owner')->where('is_active', true)->first() : null;
    }

    public function sendResetLinkAction(): Action
    {
        return Action::make('sendResetLink')
            ->label('Send reset link')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-envelope')
            ->modalHeading('Email the owner a reset link?')
            ->modalDescription(function (array $arguments) {
                $o = static::ownerOf($this->ownerPwTenant($arguments));
                return $o
                    ? 'Sends ' . $o->name . ' (' . $o->email . ') a link to set a new password. It works for one hour. Their current password keeps working until they use it.'
                    : 'This shop has no active owner, so there is no one to send it to.';
            })
            ->modalSubmitActionLabel('Send link')
            ->action(function (array $arguments) {
                $t = $this->ownerPwTenant($arguments);
                $o = static::ownerOf($t);
                if (! $o || ! $o->email) {
                    Notification::make()->title('No active owner to send it to.')->warning()->send();
                    return;
                }

                $token = Str::random(64);
                Cache::put('pwd_reset_' . $token, ['tenant_id' => $t->id, 'user_id' => $o->id], now()->addMinutes(60));
                // on the shop's own address: route() from here would build intake.works, which belongs to no shop
                $url = $t->urlTo('admin/reset-password', ['token' => $token]);

                $ledger = \App\Services\EmailLedger::begin($t->id, 'staff', $o->email, 'staff_password_reset');
                try {
                    Mail::to($o->email)->send(new \App\Mail\PasswordReset($t, $o, $url));
                    \App\Services\EmailLedger::markSent($ledger);
                } catch (\Throwable $e) {
                    \App\Services\EmailLedger::void($ledger);
                    Cache::forget('pwd_reset_' . $token);
                    Notification::make()->title('The email didn’t send')->body($e->getMessage())->danger()->persistent()->send();
                    return;
                }

                debug_log()->audit('tenant_password_reset_link', 'Master admin emailed a password reset link to the owner of ' . $t->name, $o,
                    ['tenant_id' => $t->id, 'owner_email' => $o->email]);

                Notification::make()->title('Reset link sent to ' . $o->email)
                    ->body('It works for one hour. To send it yourself instead: ' . $url)
                    ->success()->persistent()->send();
            });
    }

    public function setPasswordAction(): Action
    {
        return Action::make('setPassword')
            ->label('Set password')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading('Set the owner’s password')
            ->modalDescription('Changes it now. Share it with them directly; it is shown only once.')
            ->modalSubmitActionLabel('Set password')
            ->fillForm(fn () => ['new_password' => Str::random(14)])
            ->form(fn (array $arguments) => [
                Forms\Components\Placeholder::make('owner_target')->label('Owner')
                    ->content(function () use ($arguments) {
                        $o = static::ownerOf($this->ownerPwTenant($arguments));
                        return $o ? ($o->name . ' — ' . $o->email) : 'No active owner on this tenant.';
                    }),
                Forms\Components\TextInput::make('new_password')->label('New password')->required()->minLength(10)
                    ->helperText('Generated for you. Edit it if you like.'),
            ])
            ->action(function (array $data, array $arguments) {
                $t = $this->ownerPwTenant($arguments);
                $o = static::ownerOf($t);
                if (! $o) {
                    Notification::make()->title('No active owner found for this tenant.')->danger()->send();
                    return;
                }
                $o->update(['password' => Hash::make($data['new_password'])]);
                debug_log()->audit('tenant_password_reset', 'Master admin reset the owner password for ' . $t->name, $o,
                    ['tenant_id' => $t->id, 'owner_email' => $o->email]);
                Notification::make()->title('Password set for ' . $o->name)
                    ->body('Sign in as ' . $o->email . ' with: ' . $data['new_password'] . '. Copy it now; it is not shown again.')
                    ->success()->persistent()->send();
            });
    }
}
