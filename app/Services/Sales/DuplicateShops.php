<?php
// Finds prospects that are the same shop entered twice, and merges a set into one.
//
// Same shop = the same phone number, or the same website at the same ZIP / city.
// A chain's locations share a website but not a phone or ZIP, so they stay apart.
// The record someone has worked (tenant, stage, contact, rep, notes) is kept; the
// others fill its blanks, hand it their timeline, and are deleted.

namespace App\Services\Sales;

use App\Models\SalesProspect;
use App\Models\SalesSetting;
use Illuminate\Support\Facades\DB;

class DuplicateShops
{
    private const COLS = ['id', 'shop', 'city', 'state', 'postcode', 'phone', 'website', 'email', 'stage', 'tenant_id',
        'sales_rep_id', 'last_contacted_at', 'next_action_on', 'created_at'];

    /** @return array<int, array{ids: string[], keeper: string, reason: string}> */
    public function groups(): array
    {
        $rows = SalesProspect::query()->select(self::COLS)->get()->keyBy('id');
        $parent = [];
        $find = function ($x) use (&$parent, &$find) { return ($parent[$x] ?? $x) === $x ? $x : ($parent[$x] = $find($parent[$x])); };
        $union = function ($a, $b) use (&$parent, $find) { $ra = $find($a); $rb = $find($b); if ($ra !== $rb) $parent[$rb] = $ra; };
        $why = [];

        $byPhone = []; $bySite = [];
        foreach ($rows as $r) {
            $ph = self::phoneKey($r->phone);
            if ($ph) $byPhone[$ph][] = $r->id;
            $host = $r->website ? SiteScanner::hostOf((string) $r->website) : '';
            if ($host && ! SiteScanner::isNotShopSite($host)) {
                $where = self::zipKey($r->postcode) ?: ($r->city ? mb_strtolower(trim($r->city)) . '|' . strtoupper((string) $r->state) : '');
                if ($where !== '') $bySite[$host . '|' . $where][] = $r->id;
            }
        }
        foreach ([['same phone', $byPhone], ['same website and place', $bySite]] as [$reason, $map]) {
            foreach ($map as $ids) {
                if (count($ids) < 2 || count($ids) > 8) continue;      // 9+ on one key is a chain or junk data, not a duplicate
                foreach ($ids as $id) { $why[$id][$reason] = true; $union($ids[0], $id); }
            }
        }

        $sets = [];
        foreach (array_keys($why) as $id) $sets[$find($id)][] = $id;
        $ignored = self::ignored();
        $out = [];
        foreach ($sets as $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) continue;
            sort($ids);
            if (isset($ignored[implode(',', $ids)])) continue;
            // two different signed-up shops are never merged
            $tenants = array_unique(array_filter(array_map(fn ($i) => $rows[$i]->tenant_id, $ids)));
            if (count($tenants) > 1) continue;
            $reasons = [];
            foreach ($ids as $i) foreach (array_keys($why[$i] ?? []) as $w) $reasons[$w] = true;
            $out[] = ['ids' => $ids, 'keeper' => $this->keeperOf($ids), 'reason' => implode(' · ', array_keys($reasons))];
        }
        usort($out, fn ($a, $b) => strcmp((string) $rows[$a['keeper']]->shop, (string) $rows[$b['keeper']]->shop));
        return $out;
    }

    /** The record worth keeping: signed up, worked, then most filled in, then oldest. */
    public function keeperOf(array $ids): string
    {
        $acts = DB::table('sales_activities')->whereIn('sales_prospect_id', $ids)->where('type', '!=', 'system')
            ->select('sales_prospect_id', DB::raw('COUNT(*) n'))->groupBy('sales_prospect_id')->pluck('n', 'sales_prospect_id');
        $best = null; $bestScore = null;
        foreach (SalesProspect::whereIn('id', $ids)->get() as $p) {
            $filled = 0;
            foreach (['email', 'phone', 'website', 'address', 'owner_contact', 'postcode', 'lat', 'socials', 'brands', 'notes'] as $c) if (filled($p->{$c})) $filled++;
            $score = [($p->tenant_id ? 1 : 0), ($p->stage !== 'prospect' ? 1 : 0), (int) ($acts[$p->id] ?? 0),
                ($p->last_contacted_at ? 1 : 0), ($p->next_action_on ? 1 : 0), ($p->sales_rep_id ? 1 : 0), $filled,
                -($p->created_at?->timestamp ?? 0)];
            if ($bestScore === null || $score > $bestScore) { $best = $p->id; $bestScore = $score; }
        }
        return (string) $best;
    }

    /** Merge a set into its keeper. Returns the keeper. */
    public function merge(array $ids): SalesProspect
    {
        return DB::transaction(function () use ($ids) {
            $keeperId = $this->keeperOf($ids);
            $keeper = SalesProspect::lockForUpdate()->findOrFail($keeperId);
            $others = SalesProspect::whereIn('id', array_diff($ids, [$keeperId]))->lockForUpdate()->get();
            if ($others->isEmpty()) return $keeper;

            $fill = [];
            foreach (['email', 'phone', 'website', 'address', 'city', 'state', 'postcode', 'owner_contact', 'lat', 'lng', 'hours',
                      'google_maps_url', 'primary_type', 'business_status', 'rating', 'rating_count', 'channel_id', 'territory_id',
                      'sales_rep_id', 'agency_id', 'notes', 'source_url', 'google_place_id'] as $col) {
                if (filled($keeper->{$col})) continue;
                foreach ($others as $o) if (filled($o->{$col})) { $fill[$col] = $o->{$col}; break; }
            }
            $soc = (array) ($keeper->socials ?? []);
            $brands = (array) ($keeper->brands ?? []);
            foreach ($others as $o) {
                $soc += (array) ($o->socials ?? []);
                $brands = array_values(array_unique(array_merge($brands, (array) ($o->brands ?? []))));
            }
            if ($soc) $fill['socials'] = $soc;
            if ($brands) $fill['brands'] = $brands;
            $fill['lead_score'] = max(array_merge([(int) $keeper->lead_score], $others->pluck('lead_score')->map(fn ($v) => (int) $v)->all()));
            $fill['priority'] = collect(array_merge([$keeper->priority], $others->pluck('priority')->all()))->filter()->sort()->first() ?: $keeper->priority;
            if (! $keeper->verified && $others->contains(fn ($o) => $o->verified)) $fill['verified'] = true;

            $otherIds = $others->pluck('id')->all();
            DB::table('sales_activities')->whereIn('sales_prospect_id', $otherIds)->update(['sales_prospect_id' => $keeper->id]);
            if (\Illuminate\Support\Facades\Schema::hasTable('sales_commission_entries')) {
                DB::table('sales_commission_entries')->whereIn('sales_prospect_id', $otherIds)->update(['sales_prospect_id' => $keeper->id]);
            }
            $names = $others->map(fn ($o) => trim($o->shop . ($o->city ? ', ' . $o->city : '')))->implode('; ');
            SalesProspect::whereIn('id', $otherIds)->delete();           // after the moves, so nothing cascades away
            $keeper->forceFill($fill)->save();
            $keeper->activities()->create(['type' => 'system', 'body' => 'Merged ' . count($otherIds) . ' duplicate' . (count($otherIds) === 1 ? '' : 's') . ' into this shop: ' . $names]);
            return $keeper;
        });
    }

    public static function ignore(array $ids): void
    {
        sort($ids);
        $all = self::ignored();
        $all[implode(',', $ids)] = true;
        SalesSetting::put('duplicate_shops_ignored', json_encode(array_keys($all)));
    }

    public static function ignored(): array
    {
        return array_fill_keys((array) json_decode((string) SalesSetting::get('duplicate_shops_ignored', '[]'), true), true);
    }

    public static function phoneKey(?string $p): ?string
    {
        $d = preg_replace('/\D/', '', (string) $p);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return strlen($d) === 10 ? $d : null;
    }

    public static function zipKey(?string $z): ?string
    {
        $d = substr(preg_replace('/\D/', '', (string) $z), 0, 5);
        return strlen($d) === 5 ? $d : null;
    }
}
