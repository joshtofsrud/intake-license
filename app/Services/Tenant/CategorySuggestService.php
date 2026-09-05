<?php
// MARKER-CAT-UNDO

namespace App\Services\Tenant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rule-based suggestions for the uncategorized mapper, learned from what
 * this shop has already assigned. Deterministic and explainable: every
 * suggestion carries the buckets it was learned from.
 */
class CategorySuggestService
{
    /** Record (or overwrite) bucket -> category after an assignment. */
    public function learn(string $tenantId, ?string $bucketKey, string $categoryId): void
    {
        $bucketKey = trim((string) $bucketKey);
        if ($bucketKey === '' || $bucketKey === '__none__') return;

        DB::table('tenant_bucket_rules')->updateOrInsert(
            ['tenant_id' => $tenantId, 'bucket_key' => $bucketKey],
            ['id' => DB::raw("COALESCE(id, '" . (string) Str::uuid() . "')"),
             'category_id' => $categoryId, 'hits' => DB::raw('COALESCE(hits, 0) + 1'),
             'last_used_at' => now(), 'updated_at' => now(), 'created_at' => DB::raw('COALESCE(created_at, NOW())')]
        );
    }

    /**
     * Suggestions for every bucket, keyed by bucket key:
     *   ['category_id', 'category_name', 'kind' => 'rule'|'family', 'because' => [...buckets]]
     *
     * Exact rule wins. Otherwise a FAMILY match: a bucket whose final word
     * matches a bucket already assigned ("29\" Tires" -> "Tires" family).
     */
    public function forBuckets(string $tenantId, array $bucketKeys): array
    {
        $rules = DB::table('tenant_bucket_rules as r')
            ->join('tenant_inventory_categories as c', 'c.id', '=', 'r.category_id')
            ->where('r.tenant_id', $tenantId)
            ->get(['r.bucket_key', 'r.category_id', 'c.name as category_name']);

        if ($rules->isEmpty()) return [];

        $exact = [];
        $byFamily = [];
        foreach ($rules as $r) {
            $exact[$r->bucket_key] = $r;
            $fam = $this->family($r->bucket_key);
            if ($fam !== '') {
                $byFamily[$fam][] = $r;
            }
        }

        $out = [];
        foreach ($bucketKeys as $key) {
            if (isset($exact[$key])) {
                $r = $exact[$key];
                $out[$key] = ['category_id' => $r->category_id, 'category_name' => $r->category_name,
                              'kind' => 'rule', 'because' => [$key]];
                continue;
            }
            $fam = $this->family($key);
            if ($fam === '' || empty($byFamily[$fam])) continue;

            // Majority category among the family's rules.
            $votes = [];
            foreach ($byFamily[$fam] as $r) {
                $votes[$r->category_id]['n'] = ($votes[$r->category_id]['n'] ?? 0) + 1;
                $votes[$r->category_id]['name'] = $r->category_name;
                $votes[$r->category_id]['from'][] = $r->bucket_key;
            }
            uasort($votes, fn ($a, $b) => $b['n'] <=> $a['n']);
            $cid = array_key_first($votes);
            $out[$key] = ['category_id' => $cid, 'category_name' => $votes[$cid]['name'],
                          'kind' => 'family', 'because' => array_slice($votes[$cid]['from'], 0, 3)];
        }

        return $out;
    }

    /** "29\" Tires" -> "tires"; "Pedals - Platform" -> "pedals"; "Locks - U-Locks" -> "locks". */
    private function family(string $bucket): string
    {
        $b = strtolower($bucket);
        $b = preg_replace('/[\d.\/"\'x×]+/', ' ', $b);      // sizes
        $head = trim(explode(' - ', $b)[0]);                 // "Pedals - Platform" -> "pedals"
        $words = array_values(array_filter(preg_split('/[^a-z&]+/', $head)));
        if (! $words) return '';
        $stop = ['and', 'of', 'the', 'for', 'parts', 'accessories', 'road', 'mountain', 'mtn', 'bmx'];
        $words = array_values(array_filter($words, fn ($w) => ! in_array($w, $stop, true))) ?: $words;
        return end($words) ?: '';
    }
}
