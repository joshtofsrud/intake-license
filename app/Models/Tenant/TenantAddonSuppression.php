<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class TenantAddonSuppression extends Model
{
    protected $table = 'tenant_addon_suppressions';

    protected $fillable = [
        'tenant_id',
        'addon_code',
        'suppressed_by_user_id',
        'reason',
        'suppressed_at',
        'lifted_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('lifted_at');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
