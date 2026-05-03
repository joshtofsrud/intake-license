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
    protected $fillable = ['tenant_id','name','email','phone','password','role','is_active','last_login_at'];
    protected $hidden   = ['password','remember_token'];
    protected $casts    = ['is_active' => 'boolean', 'last_login_at' => 'datetime'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
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
