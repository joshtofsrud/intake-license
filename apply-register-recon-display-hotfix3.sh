#!/bin/bash
# register-recon-display hotfix 3 — tenant ids are UUID strings, not ints.
set -e
cd "$(git rev-parse --show-toplevel)"
cat > app/Models/Tenant/TenantRegister.php <<'RRD_H3_EOF'
<?php

namespace App\Models\Tenant;

// MARKER-REGISTER-RECON-DISPLAY

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantRegister extends Model
{
    protected $table = 'tenant_registers';

    protected $fillable = [
        'tenant_id', 'location_id', 'number', 'name',
        'display_token', 'display_cart', 'cart_updated_at', 'is_active',
    ];

    protected $casts = [
        'display_cart'    => 'array',
        'cart_updated_at' => 'datetime',
        'is_active'       => 'boolean',
    ];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function location(): BelongsTo { return $this->belongsTo(TenantLocation::class, 'location_id'); }

    public static function freshToken(): string
    {
        return Str::random(48);
    }

    /** Next register number for a tenant (1, 2, 3, …). */
    public static function nextNumber(string $tenantId): int // tenant ids are UUIDs
    {
        return (int) static::where('tenant_id', $tenantId)->max('number') + 1;
    }
}
RRD_H3_EOF
echo "hotfix 3 applied"
