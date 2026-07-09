<?php
// MARKER-PATCH-621 — logged shop search query.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantSearchQuery extends Model
{
    use HasUuids;

    public $timestamps = false; // created_at only, set explicitly

    protected $table = 'tenant_search_queries';

    protected $fillable = ['tenant_id', 'session_id', 'query', 'results_count', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Log a search, collapsing keystroke prefixes: if this session's latest
     * recent query is a prefix of the new one (user still typing — "mar" →
     * "marin"), update that row instead of inserting a new one.
     */
    public static function log(string $tenantId, ?string $sessionId, string $query, int $results): void
    {
        $query = mb_substr(trim($query), 0, 190);
        if (mb_strlen($query) < 3) return;

        if ($sessionId) {
            $recent = static::where('tenant_id', $tenantId)
                ->where('session_id', $sessionId)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('created_at')
                ->first();

            if ($recent && (
                str_starts_with(mb_strtolower($query), mb_strtolower($recent->query)) ||
                str_starts_with(mb_strtolower($recent->query), mb_strtolower($query))
            )) {
                $recent->update(['query' => $query, 'results_count' => $results, 'created_at' => now()]);
                return;
            }
        }

        static::create([
            'tenant_id'     => $tenantId,
            'session_id'    => $sessionId,
            'query'         => $query,
            'results_count' => $results,
            'created_at'    => now(),
        ]);
    }
}

