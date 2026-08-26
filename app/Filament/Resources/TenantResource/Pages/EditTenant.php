<?php
namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant\TenantUser;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * MARKER-OWNER-FIELDS-SAVE — the owner_* fields are dehydrated(false)
     * because they are not columns on `tenants`. They were also never written
     * anywhere, so edits vanished. Write them to the owner's row here.
     */
    protected function afterSave(): void
    {
        $state = $this->form->getRawState();

        $name  = trim((string) ($state['owner_name']  ?? ''));
        $email = trim((string) ($state['owner_email'] ?? ''));
        $phone = trim((string) ($state['owner_phone'] ?? ''));

        if ($name === '' && $email === '' && $phone === '') {
            return;
        }

        $owner = TenantUser::where('tenant_id', $this->record->id)
            ->where('role', 'owner')
            ->first();

        if (! $owner) {
            Notification::make()->warning()
                ->title('Owner details not saved')
                ->body('This tenant has no owner account to write them to.')
                ->send();
            return;
        }

        // Email is the login. A duplicate inside this tenant would leave one
        // of the two unable to sign in, so refuse rather than write it.
        if ($email !== '' && strtolower($email) !== strtolower((string) $owner->email)) {
            $taken = TenantUser::where('tenant_id', $this->record->id)
                ->where('id', '!=', $owner->id)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->exists();

            if ($taken) {
                Notification::make()->danger()
                    ->title('That email is already used by another team member')
                    ->body('Owner details were not changed. Everything else saved.')
                    ->persistent()->send();
                return;
            }
        }

        $before = ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone];

        if ($name !== '')  $owner->name  = $name;
        if ($email !== '') $owner->email = $email;
        $owner->phone = $phone !== '' ? $phone : null;

        if (! $owner->isDirty()) {
            return;
        }

        $changed = array_keys($owner->getDirty());
        $owner->save();

        // Changing someone's login is a security event, not a profile tweak.
        Log::info('Tenant owner account edited via master admin', [
            'tenant_id' => $this->record->id,
            'owner_id'  => $owner->id,
            'changed'   => $changed,
            'before'    => $before,
            'by'        => auth()->id(),
        ]);

        if (in_array('email', $changed, true)) {
            Notification::make()->success()
                ->title('Owner sign-in address changed')
                ->body("They now sign in as {$owner->email}. Tell them — they won't be notified automatically.")
                ->persistent()->send();
        }
    }
}
