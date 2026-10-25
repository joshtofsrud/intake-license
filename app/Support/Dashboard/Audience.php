<?php

namespace App\Support\Dashboard;

use App\Models\Tenant;
use App\Services\FeatureAccessService;

/**
 * MARKER-DASH-AUDIENCE: which shops a dashboard template is offered to.
 *
 *   mode  all     every shop
 *         match   shops on at least `plan`, of one of `industries`, with all of `addons`
 *         chosen  only the shops in `include` (a beta)
 *   include  shop ids always offered it (any mode)
 *   exclude  shop ids never offered it (wins over everything)
 *
 * Stored as JSON on dashboard_templates.audience; null means every shop.
 */
class Audience
{
    public const PLANS = ['starter' => 'Starter', 'branded' => 'Branded', 'scale' => 'Scale', 'custom' => 'Custom'];

    public static function normalize(?array $a): array
    {
        $a = (array) $a;
        $mode = in_array($a['mode'] ?? 'all', ['all', 'match', 'chosen'], true) ? ($a['mode'] ?? 'all') : 'all';
        $ids = fn ($v) => array_values(array_unique(array_filter(array_map('strval', (array) $v), fn ($x) => $x !== '')));

        return [
            'mode'       => $mode,
            'plan'       => isset(self::PLANS[$a['plan'] ?? '']) ? $a['plan'] : null,
            'industries' => array_values(array_intersect(array_keys((array) config('industry_packs', [])), (array) ($a['industries'] ?? []))),
            'addons'     => $ids($a['addons'] ?? []),
            'include'    => $ids($a['include'] ?? []),
            'exclude'    => $ids($a['exclude'] ?? []),
        ];
    }

    public static function isEveryone(?array $a): bool
    {
        $a = self::normalize($a);

        return $a['mode'] === 'all' && ! $a['exclude'];
    }

    /** Whether the shop is offered the template, and why, in words. */
    public static function check(?array $a, Tenant $tenant): array
    {
        $a = self::normalize($a);
        $id = (string) $tenant->id;
        if (in_array($id, $a['exclude'], true)) return [false, 'left out by name'];
        if (in_array($id, $a['include'], true)) return [true, 'added by name'];
        if ($a['mode'] === 'all') return [true, 'every shop gets it'];
        if ($a['mode'] === 'chosen') return [false, 'not one of the chosen shops'];

        $rank = array_flip(array_keys(self::PLANS));
        if ($a['plan'] && ($rank[$tenant->plan_tier ?? 'starter'] ?? 0) < $rank[$a['plan']]) {
            return [false, 'on the ' . (self::PLANS[$tenant->plan_tier ?? 'starter'] ?? $tenant->plan_tier) . ' plan'];
        }
        if ($a['industries'] && ! in_array($tenant->industry_pack, $a['industries'], true)) {
            $label = config('industry_packs.' . $tenant->industry_pack . '.label') ?? 'no business type set';
            return [false, 'business type is ' . $label];
        }
        foreach ($a['addons'] as $code) {
            if (! app(FeatureAccessService::class)->hasAddon($tenant, $code)) {
                return [false, 'no ' . self::addonName($code) . ' add-on'];
            }
        }

        return [true, 'matches'];
    }

    public static function allows(?array $a, Tenant $tenant): bool
    {
        return self::check($a, $tenant)[0];
    }

    /** One line for lists: "Every shop", "Scale plan and up, with Classes", "3 chosen shops". */
    public static function describe(?array $a): string
    {
        $a = self::normalize($a);
        $tail = ($a['include'] ? ' + ' . count($a['include']) . ' by name' : '') . ($a['exclude'] ? ', not ' . count($a['exclude']) : '');
        if ($a['mode'] === 'all') return 'Every shop' . ($a['exclude'] ? ' but ' . count($a['exclude']) : '');
        if ($a['mode'] === 'chosen') return count($a['include']) . ' chosen ' . \Illuminate\Support\Str::plural('shop', count($a['include'])) . ($a['exclude'] ? ', not ' . count($a['exclude']) : '');
        $parts = [];
        if ($a['plan']) $parts[] = self::PLANS[$a['plan']] . ' plan and up';
        if ($a['industries']) $parts[] = collect($a['industries'])->map(fn ($k) => config("industry_packs.$k.label", $k))->implode(' or ');
        if ($a['addons']) $parts[] = 'with ' . collect($a['addons'])->map(fn ($c) => self::addonName($c))->implode(' and ');

        return ($parts ? implode(', ', $parts) : 'Every shop') . $tail;
    }

    /** How many active shops get it. Cached briefly: checking add-ons costs a query or two per shop. */
    public static function countMatching(?array $a): int
    {
        $a = self::normalize($a);

        return \Illuminate\Support\Facades\Cache::remember('dash-aud:' . md5(json_encode($a)), 300, function () use ($a) {
            if ($a['mode'] === 'all') {
                return Tenant::where('is_active', true)->whereNotIn('id', $a['exclude'] ?: [''])->count();
            }
            $n = 0;
            Tenant::where('is_active', true)->select('*')->chunk(200, function ($chunk) use ($a, &$n) {
                foreach ($chunk as $t) {
                    if (self::allows($a, $t)) $n++;
                }
            });

            return $n;
        });
    }

    public static function addonName(string $code): string
    {
        static $names = null;
        if ($names === null) {
            try {
                $names = \Illuminate\Support\Facades\DB::table('addons')->pluck('name', 'code')->all();
            } catch (\Throwable $e) {
                $names = [];
            }
        }

        return $names[$code] ?? ucwords(str_replace('_', ' ', $code));
    }
}
