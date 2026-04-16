<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
