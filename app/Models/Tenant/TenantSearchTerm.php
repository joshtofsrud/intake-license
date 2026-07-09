<?php
// MARKER-PATCH-622 — vocabulary word for typo correction.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantSearchTerm extends Model
{
    use HasUuids;

    protected $table = 'tenant_search_terms';
    protected $fillable = ['tenant_id', 'term', 'soundex', 'freq'];

    /**
     * Nearest vocabulary word for a (probably misspelled) token, or null.
     * Candidates: same soundex OR same first letter with length ±2 — a small,
     * indexed set. Winner = lowest edit distance (≤2), freq as tie-break.
     */
    public static function correct(string $tenantId, string $token): ?string
    {
        $token = mb_strtolower($token);
        if (mb_strlen($token) < 3) return null;

        $candidates = static::where('tenant_id', $tenantId)
            ->where(function ($q) use ($token) {
                $q->where('soundex', soundex($token))
                  ->orWhere(function ($w) use ($token) {
                      $w->where('term', 'like', mb_substr($token, 0, 1) . '%')
                        ->whereRaw('CHAR_LENGTH(term) BETWEEN ? AND ?', [mb_strlen($token) - 2, mb_strlen($token) + 2]);
                  });
            })
            ->limit(200)
            ->get(['term', 'freq']);

        $best = null; $bd = 3; $bf = 0;
        foreach ($candidates as $c) {
            if ($c->term === $token) return null; // token is already a real word
            $d = levenshtein($token, $c->term);
            if ($d < $bd || ($d === $bd && $c->freq > $bf)) {
                $bd = $d; $best = $c->term; $bf = $c->freq;
            }
        }
        return $bd <= 2 ? $best : null;
    }
}

