<?php

namespace App\Support\ShopSearch;

use App\Models\Tenant;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantSearchRule;
use App\Models\Tenant\TenantSearchTerm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-SEARCH-V2: the numbers behind Reports › Search.
 *
 *   Searches     every logged search (typing "char", "charg", "charger" is one search)
 *   Clicked      searches where the shopper opened an item from the dropdown or results
 *   Added        searches where that item went into the cart within 30 minutes
 *   Bought       searches whose added item was in a cart that was then paid for
 *   Revenue      what those items sold for on those orders
 */
class SearchReport
{
    public const PAID = [TenantOrder::STATUS_PAID, TenantOrder::STATUS_FULFILLING, TenantOrder::STATUS_FULFILLED, TenantOrder::STATUS_COMPLETED];

    public CarbonImmutable $from;
    public CarbonImmutable $to;

    public function __construct(public Tenant $tenant, public int $days = 30)
    {
        $this->days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $this->to   = CarbonImmutable::now()->addSecond();
        $this->from = $this->to->subDays($this->days);
    }

    private function queries(?CarbonImmutable $from = null, ?CarbonImmutable $to = null)
    {
        return DB::table('tenant_search_queries')->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $from ?? $this->from)->where('created_at', '<=', $to ?? $this->to);
    }

    private function events(string $kind)
    {
        return DB::table('tenant_search_events as e')
            ->join('tenant_search_queries as q', 'q.id', '=', 'e.query_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.kind', $kind)
            ->where('q.created_at', '>=', $this->from)->where('q.created_at', '<=', $this->to);
    }

    private function bought()
    {
        return $this->events('add')
            ->join('tenant_orders as o', 'o.id', '=', 'e.order_id')
            ->whereIn('o.status', self::PAID);
    }

    public function overview(): array
    {
        $n     = (clone $this->queries())->count();
        $prior = (clone $this->queries($this->from->subDays($this->days), $this->from))->count();
        $zero  = (clone $this->queries())->where('results_count', 0)->count();
        $click = (clone $this->events('click'))->distinct()->count('e.query_id');
        $add   = (clone $this->events('add'))->distinct()->count('e.query_id');
        $buy   = (clone $this->bought())->distinct()->count('e.query_id');
        $rev   = (int) (clone $this->bought())
            ->join('tenant_order_items as oi', function ($j) { $j->on('oi.order_id', '=', 'e.order_id')->on('oi.inventory_item_id', '=', 'e.item_id'); })
            ->sum('oi.line_total_cents');
        $pct = fn ($a) => $n ? round($a / $n * 100, 1) : 0;
        return [
            'searches' => $n,
            'change'   => $prior ? round(($n - $prior) / $prior * 100) : null,
            'clicked'  => $pct($click),
            'added'    => $pct($add),
            'bought'   => $pct($buy),
            'revenue'  => $rev,
            'zero'     => $zero,
            'zero_pct' => $pct($zero),
            'promo_clicks' => DB::table('tenant_search_events')->where('tenant_id', $this->tenant->id)->where('kind', 'promo_click')->where('created_at', '>=', $this->from)->count(),
        ];
    }

    /** The most searched words, with what shoppers did next and the rules already on them. */
    public function top(int $limit = 25): array
    {
        $rows = (clone $this->queries())
            ->selectRaw('LOWER(query) AS k, COUNT(*) AS n, AVG(results_count) AS r')
            ->groupBy('k')->orderByDesc('n')->limit($limit)->get();
        if ($rows->isEmpty()) return [];
        $keys = $rows->pluck('k')->all();

        $per = function ($q) use ($keys) {
            return $q->whereIn(DB::raw('LOWER(q.query)'), $keys)
                ->selectRaw('LOWER(q.query) AS k, COUNT(DISTINCT e.query_id) AS n')
                ->groupBy('k')->pluck('n', 'k')->all();
        };
        $click = $per($this->events('click'));
        $add   = $per($this->events('add'));
        $buy   = $per($this->bought());
        $rev   = $this->bought()
            ->join('tenant_order_items as oi', function ($j) { $j->on('oi.order_id', '=', 'e.order_id')->on('oi.inventory_item_id', '=', 'e.item_id'); })
            ->whereIn(DB::raw('LOWER(q.query)'), $keys)
            ->selectRaw('LOWER(q.query) AS k, SUM(oi.line_total_cents) AS c')->groupBy('k')->pluck('c', 'k')->all();

        $rules = TenantSearchRule::where('tenant_id', $this->tenant->id)->where('enabled', true)
            ->whereIn(DB::raw('LOWER(from_term)'), $keys)->get(['type', 'from_term']);
        $tags = [];
        foreach ($rules as $r) $tags[mb_strtolower($r->from_term)][TenantSearchRule::TYPES[$r->type] ?? $r->type] = true;

        return $rows->map(function ($r) use ($click, $add, $buy, $rev, $tags) {
            $n = (int) $r->n;
            $pc = fn ($a) => $n ? (int) round(($a[$r->k] ?? 0) / $n * 100) : 0;
            return [
                'q' => $r->k, 'n' => $n, 'results' => (int) round((float) $r->r),
                'clicked' => $pc($click), 'added' => $pc($add), 'bought' => $pc($buy),
                'revenue' => (int) ($rev[$r->k] ?? 0), 'rules' => array_keys($tags[$r->k] ?? []),
            ];
        })->all();
    }

    /** Searches that found nothing, not yet looked at, each with the likeliest fix. */
    public function zero(int $limit = 40): array
    {
        $dismissed = DB::table('tenant_search_dismissals')->where('tenant_id', $this->tenant->id)->pluck('query')->map(fn ($q) => mb_strtolower($q))->all();
        $rows = (clone $this->queries())->where('results_count', 0)
            ->selectRaw('LOWER(query) AS k, COUNT(*) AS n, MAX(created_at) AS last')
            ->groupBy('k')->orderByDesc('n')->limit($limit + count($dismissed))->get()
            ->reject(fn ($r) => in_array($r->k, $dismissed, true))->take($limit)->values();

        return $rows->map(fn ($r) => ['q' => $r->k, 'n' => (int) $r->n, 'last' => \Illuminate\Support\Carbon::parse($r->last)] + $this->fix($r->k))->all();
    }

    public function zeroCount(): int
    {
        $dismissed = DB::table('tenant_search_dismissals')->where('tenant_id', $this->tenant->id)->pluck('query')->map(fn ($q) => mb_strtolower($q))->all();
        return (clone $this->queries())->where('results_count', 0)
            ->selectRaw('LOWER(query) AS k')->groupBy('k')->pluck('k')
            ->reject(fn ($k) => in_array($k, $dismissed, true))->count();
    }

    /** What would most likely answer a search with no results. Cached ten minutes. */
    public function fix(string $q): array
    {
        return Cache::remember('shop-search-fix:' . $this->tenant->id . ':' . md5($q), 600, function () use ($q) {
            $search = ShopSearch::for($this->tenant);
            $now = $search->run($q);
            if ($now['redirect']) return ['kind' => 'done', 'fix' => 'A redirect now sends this search to ' . $now['redirect']['url'] . '.'];
            if ($now['ids']) {
                return $now['corrected']
                    ? ['kind' => 'ok', 'fix' => 'Typo of "' . $now['corrected'] . '". Search already corrects it; nothing to do.']
                    : ['kind' => 'ok', 'fix' => 'Finds ' . count($now['ids']) . ' item' . (count($now['ids']) === 1 ? '' : 's') . ' now. A rule or a new item fixed it.'];
            }
            // a page that answers it
            $words = ShopSearch::words($q);
            try {
                $page = \App\Models\Tenant\TenantPage::where('tenant_id', $this->tenant->id)->where('is_published', true)
                    ->where(function ($w) use ($words) { foreach ($words as $t) if (mb_strlen($t) >= 4) $w->orWhereRaw('LOWER(title) LIKE ?', ['%' . $t . '%'])->orWhereRaw('LOWER(slug) LIKE ?', ['%' . $t . '%']); })
                    ->first(['title', 'slug', 'is_home']);
                if ($page && $words) return ['kind' => 'redirect', 'fix' => 'A page answers this. Add a redirect to "' . $page->title . '".', 'to' => $page->is_home ? '/' : '/' . $page->slug];
            } catch (\Throwable $e) {}
            // close to a word the shop sells under
            $near = null; $best = 3;
            foreach ($words as $t) {
                if (mb_strlen($t) < 4) continue;
                $cands = TenantSearchTerm::where('tenant_id', $this->tenant->id)->where('term', 'like', mb_substr($t, 0, 2) . '%')->limit(300)->pluck('term');
                foreach ($cands as $c) {
                    $d = levenshtein($t, $c);
                    if ($d > 0 && $d < $best) { $best = $d; $near = $c; }
                }
            }
            if ($near) return ['kind' => 'syn', 'fix' => 'Close to "' . $near . '". Add a synonym if they mean the same.', 'to' => $near];
            return ['kind' => 'none', 'fix' => 'Nothing you sell matches. A signal of what to stock, or add a synonym if you sell it under another name.'];
        });
    }
}
