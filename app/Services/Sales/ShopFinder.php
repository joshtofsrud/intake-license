<?php
// MARKER-SALES-FIND — search Places, mark each result against prospects and tenants, add the ones chosen.

namespace App\Services\Sales;

use App\Models\SalesPlacesSearch;
use App\Models\SalesProspect;
use App\Models\SalesSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopFinder
{
    public const INDUSTRIES = [
        'bicycle_store'     => ['label' => 'Bike shops',        'query' => 'bike shop'],
        'ski_store'         => ['label' => 'Ski & snowboard',   'query' => 'ski and snowboard shop'],
        'gym'               => ['label' => 'Fitness studios',   'query' => 'fitness studio'],
        'motorcycle_repair' => ['label' => 'Motorcycle',        'query' => 'motorcycle repair shop'],
        'outdoor'           => ['label' => 'Outdoor specialty', 'query' => 'outdoor gear shop'],
        'paddle'            => ['label' => 'Paddle & kayak',    'query' => 'kayak and paddleboard shop'],
        'custom'            => ['label' => 'Custom…',           'query' => ''],
    ];

    /**
     * @return array{located:?string, results:array<int,array>, requests:int, cost_cents:int, error:?string}
     */
    public function search(string $industry, string $customQuery, string $place, int $radiusMiles, ?int $userId = null): array
    {
        $client = new PlacesClient();
        if (! $client->configured()) {
            return ['located' => null, 'results' => [], 'requests' => 0, 'cost_cents' => 0, 'error' => 'Add a Google Places key first.'];
        }
        $q = $industry === 'custom' ? trim($customQuery) : (self::INDUSTRIES[$industry]['query'] ?? 'bike shop');
        if ($q === '') {
            return ['located' => null, 'results' => [], 'requests' => 0, 'cost_cents' => 0, 'error' => 'Type what to search for.'];
        }
        try {
            $loc = $client->locate($place);
            if (! $loc) {
                return ['located' => null, 'results' => [], 'requests' => $client->requests, 'cost_cents' => 0, 'error' => "Couldn't find \"$place\" on the map. Try adding the state."];
            }
            $raw = $client->search("$q near $place", $loc['lat'], $loc['lng'], (int) round($radiusMiles * 1609.34));
        } catch (\Throwable $e) {
            return ['located' => null, 'results' => [], 'requests' => $client->requests, 'cost_cents' => 0, 'error' => $e->getMessage()];
        }

        $rows = [];
        $seen = [];
        foreach ($raw as $p) {
            $n = PlacesClient::normalise($p);
            if (! $n['place_id'] || ! $n['shop'] || isset($seen[$n['place_id']])) continue;
            $seen[$n['place_id']] = true;
            if ($n['lat'] !== null) {
                $n['miles'] = round(self::miles($loc['lat'], $loc['lng'], $n['lat'], $n['lng']), 1);
            }
            $rows[] = $n;
        }
        $rows = $this->mark($rows);

        $cost = $client->requests * SalesSetting::placesCostCents();
        SalesPlacesSearch::create([
            'user_id'      => $userId,
            'industry'     => $industry === 'custom' ? mb_substr($q, 0, 80) : $industry,
            'place'        => mb_substr($place, 0, 191),
            'radius_miles' => $radiusMiles,
            'requests'     => $client->requests,
            'found'        => count($rows),
            'new_count'    => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
            'cost_cents'   => $cost,
        ]);

        return ['located' => $loc['label'], 'results' => $rows, 'requests' => $client->requests, 'cost_cents' => $cost, 'error' => null];
    }

    /** Adds status new | prospect | tenant, plus prospect_id and the territory the shop would land in. */
    public function mark(array $rows): array
    {
        $ids   = array_values(array_filter(array_column($rows, 'place_id')));
        $byPid = SalesProspect::query()->whereIn('google_place_id', $ids)->get(['id', 'google_place_id', 'tenant_id', 'stage'])->keyBy('google_place_id');

        $names = array_map(fn ($r) => Str::lower($r['shop']), $rows);
        $byName = SalesProspect::query()
            ->whereIn(DB::raw('LOWER(shop)'), $names)
            ->get(['id', 'shop', 'city', 'tenant_id', 'stage']);
        $tenantNames = Tenant::query()->where('is_platform', false)->whereIn(DB::raw('LOWER(name)'), $names)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [Str::lower($name) => $id]);

        foreach ($rows as &$r) {
            $r['status'] = 'new'; $r['prospect_id'] = null; $r['stage'] = null;
            $hit = $byPid[$r['place_id']] ?? null;
            if (! $hit) {
                $hit = $byName->first(fn ($p) => Str::lower($p->shop) === Str::lower($r['shop'])
                    && Str::lower((string) $p->city) === Str::lower((string) $r['city']));
            }
            if ($hit) {
                $r['prospect_id'] = $hit->id;
                $r['stage']       = $hit->stage;
                $r['status']      = $hit->tenant_id ? 'tenant' : 'prospect';
            } elseif (isset($tenantNames[Str::lower($r['shop'])])) {
                $r['status'] = 'tenant';
            }
            $t = TerritoryResolver::resolve($r['state'], $r['lat']);
            $r['territory']       = $t?->name;
            $r['territory_owner'] = $t?->ownerLabel();
        }
        unset($r);
        return $rows;
    }

    /** Creates a prospect from a normalised result. Returns the row (existing one if the place id already exists). */
    public function add(array $r, bool $autoAssign, ?string $searchedPlace = null, ?string $by = null): SalesProspect
    {
        if ($r['place_id'] && ($existing = SalesProspect::where('google_place_id', $r['place_id'])->first())) {
            return $existing;
        }
        $p = SalesProspect::create([
            'shop'            => mb_substr($r['shop'], 0, 191),
            'city'            => $r['city'],
            'state'           => $r['state'],
            'postcode'        => $r['postcode'],
            'address'         => $r['address'],
            'lat'             => $r['lat'],
            'lng'             => $r['lng'],
            'google_place_id' => $r['place_id'],
            'google_maps_url' => $r['maps_url'],
            'website'         => $r['website'],
            'phone'           => $r['phone'],
            'hours'           => $r['hours'],
            'enriched_at'     => now(),
            'rating'          => $r['rating'],
            'rating_count'    => $r['rating_count'],
            'business_status' => $r['gstatus'] ?? null,
            'primary_type'    => $r['primary_type'],
            'stage'           => 'prospect',
            'priority'        => ($r['rating'] ?? 0) >= 4.5 && ($r['rating_count'] ?? 0) >= 50 ? 'A' : 'B',
            'verified'        => false,
            'lead_score'      => self::score($r),
            'best_ask'        => '15-min owner/service-manager demo',
            'source'          => 'Google Places',
            'notes'           => 'Found in master admin' . ($searchedPlace ? " · search near $searchedPlace" : '') . ($by ? " · $by" : ''),
        ]);
        if ($autoAssign) {
            TerritoryResolver::apply($p);
        }
        $p->activities()->create(['type' => 'note', 'body' => 'Added from Places search' . ($searchedPlace ? " near $searchedPlace" : '')]);
        return $p;
    }

    public static function score(array $r): int
    {
        $s = 20;
        if (! empty($r['website']))                 $s += 10;
        if (($r['rating'] ?? 0) >= 4.5)             $s += 15;
        if (($r['rating_count'] ?? 0) >= 100)       $s += 10;
        if (! empty($r['phone']))                   $s += 5;
        if (! empty($r['territory']))               $s += 10;
        return min($s, 99);
    }

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(sqrt($a));
    }
}
