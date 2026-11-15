<?php
// tenant-managed search rule (synonym or redirect).

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantSearchRule extends Model
{
    use HasUuids;

    protected $table = 'tenant_search_rules';
    // MARKER-SHOP-SEARCH-V2: types synonym | redirect | stop | pin | boost | hide; item_id for the last three
    protected $fillable = ['tenant_id', 'type', 'from_term', 'to_value', 'item_id', 'label', 'enabled', 'hits', 'created_by'];
    protected $casts = ['enabled' => 'boolean', 'hits' => 'integer'];

    public const TYPES = ['synonym' => 'Synonym', 'redirect' => 'Redirect', 'stop' => 'Stop word', 'pin' => 'Pin', 'boost' => 'Boost', 'hide' => 'Hide'];

    public function item()
    {
        return $this->belongsTo(TenantInventoryItem::class, 'item_id');
    }

    /**
     * Seeded synonym defaults (word => canonical). Domain vocabulary for
     * service shops; tenant rules override/extend. Applied token-wise.
     */
    public const SEED_SYNONYMS = [
        'mtb'       => 'mountain',
        'derailer'  => 'derailleur',
        'mudguard'  => 'fender',
        'mudguards' => 'fenders',
        'cycle'     => 'bike',
        'bicycle'   => 'bike',
        'wheelset'  => 'wheel',
        'innertube' => 'tube',
    ];

    /** Merged synonym map for a tenant: seeds + tenant rules (tenant wins). */
    public static function synonymMap(string $tenantId): array
    {
        $map = self::SEED_SYNONYMS;
        static::where('tenant_id', $tenantId)->where('type', 'synonym')
            ->get(['from_term', 'to_value'])
            ->each(function ($r) use (&$map) {
                $map[mb_strtolower($r->from_term)] = mb_strtolower($r->to_value);
            });
        return $map;
    }

    /** Redirect rule matching the whole query, or null. Bumps hit count. */
    public static function redirectFor(string $tenantId, string $query): ?self
    {
        $rule = static::where('tenant_id', $tenantId)
            ->where('type', 'redirect')
            ->where('enabled', true)
            ->whereRaw('LOWER(from_term) = ?', [mb_strtolower(trim($query))])
            ->first();
        if ($rule) $rule->increment('hits');
        return $rule;
    }
}

