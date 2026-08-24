<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_admin', 'role', 'suspended_at']; // MARKER-ADMIN-ROLES

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_admin'          => 'boolean',
        'suspended_at'      => 'datetime', // MARKER-ADMIN-ROLES
    ];

    /**
     * Gate access to the Filament admin panel.
     *
     * Three layers, first match wins:
     *   1. `ADMIN_EMAIL` env var — the bootstrap admin always gets in
     *   2. `is_admin` boolean column (preferred going forward)
     *   3. Fallback: allow when the `is_admin` column doesn't exist yet
     *      (safety valve for servers that haven't run the new migration)
     */
    // MARKER-REPPANEL-GATE — the /rep panel admits linked reps ONLY, and rep
    // accounts (is_admin=false) can never pass the admin checks below.
    public function salesRep()
    {
        return $this->hasOne(\App\Models\SalesRep::class, 'user_id');
    }

    // MARKER-ADMIN-GATE — the same admin test canAccessPanel applies, callable
    // from route middleware. Bridge routes (impersonation, marketing pages)
    // MUST use this: 'auth' alone also admits rep accounts.
    public function isMasterAdmin(): bool
    {
        // MARKER-ADMIN-ROLES — now role-based: owner or admin, never suspended.
        if (($this->suspended_at ?? null) !== null) {
            return false;
        }
        return in_array($this->roleName(), ['owner', 'admin'], true);
    }

    /**
     * MARKER-ADMIN-ROLES — the user's effective role. Bootstrap ADMIN_EMAIL is
     * always owner. Pre-migration fallback keeps the old is_admin semantics.
     */
    public function roleName(): ?string
    {
        $bootstrap = strtolower((string) config('intake.admin_email', ''));
        if ($bootstrap !== '' && strtolower((string) $this->email) === $bootstrap) {
            return 'owner';
        }
        $attrs = $this->getAttributes();
        $role = $attrs['role'] ?? null;
        if ($role !== null && $role !== '') {
            return $role;
        }
        if (array_key_exists('is_admin', $attrs)) {
            return $this->is_admin ? 'admin' : null;
        }
        return 'admin'; // columns not migrated yet — same safety valve as before
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'rep') {
            return $this->salesRep()->where('status', 'active')->exists();
        }

        // MARKER-ADMIN-ROLES — the admin panel admits all four staff roles;
        // per-area access inside it is enforced by EnforceAdminArea.
        // Suspension blocks the panel outright.
        if (($this->suspended_at ?? null) !== null) {
            return false;
        }
        return in_array($this->roleName(), \App\Support\AdminAccess::STAFF_ROLES, true);
    }
}
