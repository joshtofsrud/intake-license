<?php
namespace App\Models\Tenant;

use App\Models\Tenant;
use App\Support\SectionRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// MARKER-PATCH-490 — custom named roles with per-section visibility
class TenantRole extends Model
{
    use HasUuids;

    protected $table = 'tenant_roles';
    protected $fillable = ['tenant_id', 'name', 'sections', 'is_system'];
    protected $casts = ['sections' => 'array', 'is_system' => 'boolean'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function users(): HasMany    { return $this->hasMany(TenantUser::class, 'role_id'); }

    /** NULL sections means full access. */
    public function allowsSection(string $key): bool
    {
        if ($this->sections === null) return true;
        return in_array($key, $this->sections, true);
    }

    /** The locked full-access role. */
    public function isOwnerRole(): bool
    {
        return $this->is_system && $this->name === 'Owner';
    }

    /**
     * Ensure the three system roles exist for a tenant.
     * Safe to call anywhere (UI page load, tenant provisioning).
     */
    public static function ensureDefaults(string $tenantId): void
    {
        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            static::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $name],
                ['sections' => null, 'is_system' => true]
            );
        }
    }
}
