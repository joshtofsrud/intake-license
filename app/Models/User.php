<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_admin'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_admin'          => 'boolean',
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
    public function canAccessPanel(Panel $panel): bool
    {
        // Bootstrap admin from env is always allowed
        $bootstrap = strtolower((string) env('ADMIN_EMAIL', ''));
        if ($bootstrap !== '' && strtolower((string) $this->email) === $bootstrap) {
            return true;
        }

        // If the column exists and is true, allow
        if (array_key_exists('is_admin', $this->getAttributes())) {
            return (bool) $this->is_admin;
        }

        // Column not present yet (migration hasn't run on this box) — allow,
        // because if you've authenticated as a row in `users` at all, you
        // were put there deliberately. Tighten this once the migration runs.
        return true;
    }
}
