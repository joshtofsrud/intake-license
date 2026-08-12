<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// MARKER-INVEST-SITE
class InvestToken extends Model
{
    protected $fillable = ['token', 'label', 'is_active', 'views', 'last_viewed_at', 'revoked_at'];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_viewed_at' => 'datetime',
        'revoked_at'     => 'datetime',
    ];

    public function leads()
    {
        return $this->hasMany(InvestLead::class);
    }

    public static function current(): ?self
    {
        return static::where('is_active', true)->latest('id')->first();
    }

    /** Deactivate every live token and issue a fresh one. Old links stop working immediately. */
    public static function rotate(?string $label = null): self
    {
        static::where('is_active', true)->update([
            'is_active'  => false,
            'revoked_at' => now(),
        ]);

        return static::create([
            'token'     => Str::lower(Str::random(40)),
            'label'     => $label,
            'is_active' => true,
        ]);
    }
}
