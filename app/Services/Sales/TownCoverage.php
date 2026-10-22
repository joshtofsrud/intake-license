<?php
// MARKER-TOWN-LIST: the towns in a state (GeoNames, over 1,000 people) marked by
// whether a Find shops search for an industry already covered them: inside the
// circle (town + radius) of an earlier search for that same industry.

namespace App\Services\Sales;

use App\Models\SalesPlacesSearch;
use App\Models\SalesProspect;
use Illuminate\Support\Facades\DB;

class TownCoverage
{
    /** MARKER-PARTLY-COVERED: Google returns at most 60 shops a search; one that comes back this full likely missed some. */
    public const CAP = 55;

    /** Two-letter code => name, in name order (the state picker). */
    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
        'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
        'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
        'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ];

    /** "Richland, WA", "kellogg, id", "12 Main St, Spokane, Washington 99201" => [town, ST], or null. */
    public static function splitPlace(string $place): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $place)), fn ($p) => $p !== ''));
        if (count($parts) < 2) return null;
        $st = trim((string) preg_replace('/\s*\d[\d\-\s]*$/', '', end($parts)));
        $up = strtoupper($st);
        if (! isset(self::STATES[$up])) {
            $up = array_search(mb_strtolower($st), array_map('mb_strtolower', self::STATES), true);
            if ($up === false) return null;
        }
        return [$parts[count($parts) - 2], $up];
    }

    /** A place typed on Find shops, matched to a town in the list (for searches made before the list existed). */
    public static function locate(string $place): ?array
    {
        $s = self::splitPlace($place);
        if (! $s) return null;
        $t = DB::table('sales_towns')->where('state', $s[1])->whereRaw('LOWER(name) = ?', [mb_strtolower($s[0])])
            ->orderByDesc('population')->first(['lat', 'lng']);
        return $t ? ['lat' => (float) $t->lat, 'lng' => (float) $t->lng] : null;
    }

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = fn ($d) => $d * M_PI / 180;
        $h = sin($r($lat2 - $lat1) / 2) ** 2 + cos($r($lat1)) * cos($r($lat2)) * sin($r($lng2 - $lng1) / 2) ** 2;
        return 3958.8 * 2 * asin(min(1, sqrt($h)));
    }

    /**
     * Towns in a state, biggest first, with coverage for one industry (null: no coverage, e.g. Custom).
     * @return array{rows:array<int,array>, covered:int, total:int, pop_pct:int, circles:array<int,array>}
     */
    public static function forState(string $st, ?string $channelId): array
    {
        $towns = DB::table('sales_towns')->where('state', $st)->orderByDesc('population')->get(['name', 'population', 'lat', 'lng']);
        if ($towns->isEmpty()) return ['rows' => [], 'covered' => 0, 'total' => 0, 'pop_pct' => 0, 'circles' => []];

        $box = [(float) $towns->min('lat') - .6, (float) $towns->max('lat') + .6, (float) $towns->min('lng') - .8, (float) $towns->max('lng') + .8];
        $searches = $channelId
            ? SalesPlacesSearch::query()->where('channel_id', $channelId)->whereNotNull('lat')
                ->whereBetween('lat', [$box[0], $box[1]])->whereBetween('lng', [$box[2], $box[3]])
                ->latest()->get(['place', 'lat', 'lng', 'radius_miles', 'found', 'created_at'])
            : collect();
        $pros = $channelId
            ? SalesProspect::query()->whereNull('tenant_id')->where('channel_id', $channelId)
                ->whereIn('state', [$st, self::STATES[$st] ?? $st])
                ->selectRaw('LOWER(city) AS c, COUNT(*) AS n')->groupByRaw('LOWER(city)')->pluck('n', 'c')->all()
            : [];

        $rows = []; $cov = 0; $covPop = 0; $allPop = 0;
        foreach ($towns as $t) {
            $lat = (float) $t->lat; $lng = (float) $t->lng;
            $status = 'open'; $note = 'Not covered';
            $centerFull = null;
            foreach ($searches as $s) {
                $d = self::miles($lat, $lng, (float) $s->lat, (float) $s->lng);
                if ($d >= 2 && $d > (int) $s->radius_miles) continue;
                if ((int) $s->found >= self::CAP) {
                    // hit Google's limit: only partly covered, so the town stays on the to-do list
                    $partNote = 'Partly covered: ' . self::short((string) $s->place) . " hit Google's 60-shop limit " . $s->created_at->diffForHumans()
                        . '. Try a smaller radius or a nearby town';
                    if ($d < 2) $centerFull = $partNote; // its own search came back full: a neighbor's circle doesn't settle it
                    if ($status === 'open') { $status = 'part'; $note = $partNote; }
                    continue;
                }
                if ($d < 2) { $status = 'done'; $note = 'Searched ' . $s->created_at->diffForHumans(); break; }
                if ($status !== 'cov') {
                    $status = 'cov';
                    $note = 'Covered by ' . self::short((string) $s->place) . ' (' . max(1, (int) round($d)) . ' mi)';
                }
            }
            if ($centerFull !== null && $status === 'cov') { $status = 'part'; $note = $centerFull; }
            $allPop += (int) $t->population;
            if ($status === 'done' || $status === 'cov') { $cov++; $covPop += (int) $t->population; }
            $rows[] = ['name' => $t->name, 'pop' => (int) $t->population, 'lat' => $lat, 'lng' => $lng,
                'status' => $status, 'note' => $note, 'prospects' => (int) ($pros[mb_strtolower($t->name)] ?? 0)];
        }
        return [
            'rows'    => $rows,
            'covered' => $cov,
            'total'   => count($rows),
            'pop_pct' => $allPop ? (int) floor($covPop * 100 / $allPop) : 0,
            'circles' => $searches->map(fn ($s) => [(float) $s->lat, (float) $s->lng, (int) $s->radius_miles, (int) $s->found >= self::CAP])->values()->all(),
        ];
    }

    private static function short(string $place): string
    {
        $s = self::splitPlace($place);
        return ucwords(mb_strtolower($s[0] ?? trim(explode(',', $place)[0])));
    }
}
