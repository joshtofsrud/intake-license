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
    protected $fillable = ['tenant_id', 'name', 'sections', 'capabilities', 'is_system'];
    protected $casts = ['sections' => 'array', 'capabilities' => 'array', 'is_system' => 'boolean'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function users(): HasMany    { return $this->hasMany(TenantUser::class, 'role_id'); }

    /** NULL sections means full access. */
    public function allowsSection(string $key): bool
    {
        if ($this->sections === null) return true;
        return in_array($key, $this->sections, true);
    }

    /**
     * MARKER-PATCH-611 — granular capability check.
     * NULL capabilities = full access (pre-capability roles, and Owner).
     * A capability also requires its owning section to be visible.
     */
    public function allowsCapability(string $key): bool
    {
        if ($this->isOwnerRole()) return true;
        $section = \App\Support\CapabilityRegistry::all()[$key]['section'] ?? null;
        if ($section && ! $this->allowsSection($section)) return false;
        if ($this->capabilities === null) return true;
        return in_array($key, $this->capabilities, true);
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
        $roles = [];
        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            $roles[$name] = static::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $name],
                ['sections' => null, 'is_system' => true]
            );
        }

        // MARKER-PATCH-611 — seed capability sets for system roles the first
        // time this runs post-migration (capabilities still NULL). Owner stays
        // NULL (means "all"); Manager gets management caps; Staff gets none.
        // Custom roles created before this also default to NULL = full, which
        // the editor lets the owner trim.
        foreach (['Manager', 'Staff'] as $name) {
            if ($roles[$name]->capabilities === null) {
                $roles[$name]->update([
                    'capabilities' => \App\Support\CapabilityRegistry::defaultsFor($name),
                ]);
            }
        }

        // MARKER-PATCH-495 — adopt users created without a role_id (e.g. the
        // invite flow pre-495, or any future path that only sets the enum).
        // Runs on every Team page load, so stragglers self-heal.
        foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $enum => $name) {
            TenantUser::where('tenant_id', $tenantId)
                ->where('role', $enum)
                ->whereNull('role_id')
                ->update(['role_id' => $roles[$name]->id]);
        }
    }
}

