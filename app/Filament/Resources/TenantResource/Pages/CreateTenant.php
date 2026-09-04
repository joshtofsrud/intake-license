<?php
// MARKER-PATCH-142

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CreateTenant — master-admin gift flow.
 *
 * Bypasses the public Stripe-required signup. Used to create internal
 * tenants (Ground Control, demo accounts, comped customers) with full
 * access from day one.
 *
 * Differences from the public signup path (OnboardingController):
 *   - subscription_status = 'active'  (no Stripe subscription needed)
 *   - trial_ends_at       = null       (no trial countdown)
 *   - stripe_customer_id  = null       (will be set later if you bill them)
 *   - onboarding_status   = 'pending'  (same as signup — you walk through it)
 *   - settings.signup_path = 'gift'    (audit trail for future filtering)
 *
 * Owner user gets a random 12-char password. The page flashes it via a
 * persistent notification so the master-admin can copy it out.
 */
class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Form schema reuses the TenantResource\form() so we get the same
     * fields the edit page uses (Identity + Owner account sections).
     * Owner fields are dehydrated=false in the schema, so we read them
     * via the raw form state instead of $data passed to create.
     */

    /**
     * Intercept the create. Build both the Tenant and its owner
     * TenantUser inside one transaction. Generate a temp password
     * and stash it on the page instance so afterCreate() can flash it.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $state = $this->form->getRawState();
        $ownerName  = trim($state['owner_name']  ?? '');
        $ownerEmail = trim($state['owner_email'] ?? '');
        $ownerPhone = trim($state['owner_phone'] ?? '');

        // Required fields the form doesn't enforce as dehydrated=false
        if ($ownerName === '' || $ownerEmail === '') {
            Notification::make()
                ->danger()
                ->title('Owner name and email are required.')
                ->send();
            $this->halt();
        }

        // Subdomain collision check (case-insensitive — DB collation may vary)
        $sub = strtolower(trim($data['subdomain'] ?? ''));
        if ($sub === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $sub)) {
            Notification::make()
                ->danger()
                ->title('Invalid subdomain')
                ->body('Use lowercase letters, digits, and hyphens. Max 63 characters.')
                ->send();
            $this->halt();
        }
        if (Tenant::where('subdomain', $sub)->exists()) {
            Notification::make()
                ->danger()
                ->title('Subdomain already taken')
                ->body("'{$sub}' is in use.")
                ->send();
            $this->halt();
        }

        // Generate a temp password the master-admin will pass on.
        $tempPassword = Str::random(12);

        $tenant = DB::transaction(function () use ($data, $sub, $ownerName, $ownerEmail, $ownerPhone, $tempPassword) {
            $tenant = Tenant::create([
                'name'                => $data['name'],
                'subdomain'           => $sub,
                'plan_tier'           => $data['plan_tier'] ?? 'branded',
                'onboarding_status'   => $data['onboarding_status'] ?? 'pending',
                // gift-account defaults
                'subscription_status' => 'active',
                'trial_ends_at'       => null,
                'stripe_customer_id'  => null,
                'stripe_subscription_id'      => null,
                'stripe_subscription_cadence' => null,
                // sensible app defaults (mirror OnboardingController)
                'currency'            => 'USD',
                'currency_symbol'     => '$',
                'accent_color'        => '#BEF264',
                'booking_window_days' => 60,
                'min_notice_hours'    => 24,
                'booking_mode'        => 'drop_off',
                'settings'            => [
                    'signup_path'      => 'gift',
                    'onboarding_step'  => 'branding',
                    'admin_theme'      => 'a',
                    'gifted_by'        => auth()->user()?->email ?? 'unknown',
                    'gifted_at'        => now()->toIso8601String(),
                ],
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'name'      => $ownerName,
                'email'     => $ownerEmail,
                'phone'     => $ownerPhone ?: null,
                'password'  => Hash::make($tempPassword),
                'role'      => 'owner',
                'is_active' => true,
            ]);

            return $tenant;
        });

        Log::info('Gift tenant created', [
            'tenant_id' => $tenant->id,
            'subdomain' => $tenant->subdomain,
            'owner_email' => $ownerEmail,
            'created_by' => auth()->user()?->email,
        ]);

        // Stash for afterCreate() so the password isn't lost on redirect.
        session()->flash('gift_tenant_password', [
            'subdomain' => $tenant->subdomain,
            'email'     => $ownerEmail,
            'password'  => $tempPassword,
        ]);

        return $tenant;
    }

    /**
     * After redirect to the edit page, flash the temp password as a
     * persistent notification. Master-admin copies it out, hands it
     * to the gift recipient.
     */
    protected function afterCreate(): void
    {
        $stash = session('gift_tenant_password');
        if (! $stash) return;

        // MARKER-PATCH-143 — also send the welcome email with the temp password.
        try {
            $tenant = \App\Models\Tenant::find($this->record->id);
            $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $tenant->id)
                ->where('role', 'owner')->first();
            if ($tenant && $owner) {
                // MARKER-PLATFORM-MAIL-LOG — free record so this send is answerable.
                $__mailLog = \App\Services\EmailLedger::platform($owner->email, 'tenant_welcome');
                \Illuminate\Support\Facades\Mail::to($owner->email)->send(
                    new \App\Mail\WelcomeEmail($tenant, $owner, $stash['password'], 'gift')
                );
                if ($__mailLog) \App\Services\EmailLedger::markSent($__mailLog);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gift welcome email failed (non-fatal)', [
                'tenant_id' => $this->record->id, 'error' => $e->getMessage(),
            ]);
        }

        Notification::make()
            ->success()
            ->title('Gift tenant created')
            ->body("Owner sign-in:\n  Email: {$stash['email']}\n  Password: {$stash['password']}\n  URL: https://{$stash['subdomain']}.intake.works/login\n\nCopy this now — it will not be shown again. The owner has also been emailed.")
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
