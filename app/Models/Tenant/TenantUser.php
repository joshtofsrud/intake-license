<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Tenant;

class TenantUser extends Authenticatable
{
    use HasUuids;
    protected $table = 'tenant_users';
    protected $fillable = ['tenant_id','name','email','phone','password','role','role_id','admin_theme','is_active','exempt_from_timeclock','last_login_at','pin_hash','pin_set_at','pin_failed_count','pin_locked_until','pin_last_used_at'];
    protected $hidden   = ['password','remember_token','pin_hash'];
    protected $casts    = ['is_active' => 'boolean', 'exempt_from_timeclock' => 'boolean', 'last_login_at' => 'datetime', 'pin_set_at' => 'datetime', 'pin_locked_until' => 'datetime', 'pin_last_used_at' => 'datetime'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    // MARKER-PATCH-490 — named access role (custom roles & per-section visibility)
    public function accessRole(): BelongsTo { return $this->belongsTo(TenantRole::class, 'role_id'); }

    /**
     * Can this user open the given SectionRegistry key?
     * Owner enum always passes. Users without a role_id fall back to
     * legacy full access (pre-roles behavior) so nothing locks out
     * mid-migration.
     */
    public function canAccessSection(string $key): bool
    {
        if ($this->role === 'owner') return true;
        $role = $this->accessRole;
        if (!$role) return true;
        return $role->allowsSection($key);
    }

    /**
     * MARKER-PATCH-611 — granular capability check for the current user.
     * Owner enum always passes; users without a role fall back to full access
     * (pre-roles behavior) so nothing locks out unexpectedly.
     */
    public function can($key, $arguments = []): bool
    {
        if (! is_string($key)) return parent::can($key, $arguments);
        if ($this->role === 'owner') return true;
        $role = $this->accessRole;
        if (!$role) return true;
        return $role->allowsCapability($key);
    }
    public function isOwner(): bool     { return $this->role === 'owner'; }
    public function isManager(): bool   { return in_array($this->role, ['owner','manager']); }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantLocation::class,
            'tenant_user_locations',
            'tenant_user_id',
            'location_id'
        )->withPivot('is_active', 'tenant_id')->withTimestamps();
    }

    public function activeLocations(): BelongsToMany
    {
        return $this->locations()->wherePivot('is_active', true);
    }

    public function currentLocation(): ?TenantLocation
    {
        $id = session('current_location_id');
        if (!$id) return null;
        return $this->activeLocations()->where('tenant_locations.id', $id)->first();
    }
}

