<?php
// MARKER-SALES-UPLOAD · MARKER-SALES-UPLOAD2 — one importer for shop-list CSVs,
// used by the browser upload on Find shops and by intake:import-overture.
// Columns are resolved through a field→header map; when none is given the
// guesses below are used, which cover the Overture export exactly.

namespace App\Services\Sales;

use App\Models\SalesProspect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopListImporter
{
    /** Store locators and online retailers Overture sometimes attaches as a shop's website. */
    public const NOT_A_SHOP_SITE = ['chainreactioncycles.com', 'wiggle.com', 'amazon.com', 'trekbikes.com', 'specialized.com', 'giant-bicycles.com', 'cannondale.com', 'rei.com', 'walmart.com', 'target.com', 'ebay.com', 'jensonusa.com', 'competitivecyclist.com', 'backcountry.com'];

    /** Intake field => [label, required, header guesses in priority order]. */
    public const FIELDS = [
        'shop'      => ['Shop name',      true,  ['shop_name', 'name', 'shop', 'business_name', 'business', 'company', 'store', 'dealer']],
        'address'   => ['Street address', false, ['address', 'street', 'address1', 'street_address', 'addr']],
        'city'      => ['City',           false, ['city', 'locality', 'town']],
        'state'     => ['State (2-letter)', true, ['state_code', 'state', 'region', 'province', 'st']],
        'postcode'  => ['Postcode',       false, ['postcode', 'zip', 'zipcode', 'postal_code', 'zip_code']],
        'website'   => ['Website',        false, ['website', 'url', 'web', 'site', 'domain']],
        'phone'     => ['Phone',          false, ['phone', 'telephone', 'phone_number', 'tel']],
        'email'     => ['Email',          false, ['email', 'e-mail', 'contact_email']],
        'owner'     => ['Owner / contact', false, ['owner', 'contact', 'owner_name', 'contact_name']],
        'lat'       => ['Latitude',       false, ['latitude', 'lat', 'y']],
        'lng'       => ['Longitude',      false, ['longitude', 'lng', 'lon', 'long', 'x']],
        'workstand' => ['Verified dealer flag', false, ['verified_workstand', 'workstand', 'verified', 'dealer']],
        'source'    => ['Source',         false, ['source', 'data_source']],
    ];

    /** @return array{headers: string[], sample: array<int,array<string,string>>, error:?string} */
    public function inspect(string $path, int $rows = 3): array
    {
        $fh = is_file($path) ? fopen($path, 'r') : false;
        if (! $fh) return ['headers' => [], 'sample' => [], 'error' => 'File not found or unreadable.'];
        $header = fgetcsv($fh);
        if (! $header) { fclose($fh); return ['headers' => [], 'sample' => [], 'error' => 'Empty CSV.']; }
        $headers = self::cleanHeaders($header);
        $sample = [];
        while (count($sample) < $rows && ($row = fgetcsv($fh)) !== false) {
            $sample[] = array_combine($headers, array_pad(array_map(fn ($v) => trim((string) $v), $row), count($headers), ''));
        }
        fclose($fh);
        return ['headers' => $headers, 'sample' => $sample, 'error' => null];
    }

    /** Guess a field→header map from the headers present. Unmatched fields map to ''. */
    public static function guessMap(array $headers): array
    {
        $map = [];
        foreach (self::FIELDS as $field => [, , $aliases]) {
            $map[$field] = '';
            foreach ($aliases as $a) if (in_array($a, $headers, true)) { $map[$field] = $a; break; }
        }
        // a plain "state" column holding full names is common; if state_code exists prefer it (aliases already do)
        return $map;
    }

    public static function cleanHeaders(array $header): array
    {
        return array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
    }

    /**
     * @param  array<string,string>|null $map  field => header (from guessMap or the mapping screen)
     * @return array{inserted:int, matched:int, blank:int, total:int, assigned:int, with_coords:int, batch:string, error:?string}
     */
    public function import(string $path, ?string $batch = null, bool $assign = true, bool $dry = false, ?callable $progress = null, ?array $map = null): array
    {
        $batch ??= 'list-' . now()->format('Ymd-Hi');
        $out = ['inserted' => 0, 'matched' => 0, 'blank' => 0, 'total' => 0, 'assigned' => 0, 'with_coords' => 0, 'batch' => $batch, 'error' => null];

        $fh = is_file($path) ? fopen($path, 'r') : false;
        if (! $fh) { $out['error'] = 'File not found or unreadable.'; return $out; }
        $header = fgetcsv($fh);
        if (! $header) { fclose($fh); $out['error'] = 'Empty CSV.'; return $out; }
        $headers = self::cleanHeaders($header);
        $idx = array_flip($headers);
        $map ??= self::guessMap($headers);
        if (empty($map['shop']) || ! isset($idx[$map['shop']])) { fclose($fh); $out['error'] = 'No column is mapped to Shop name.'; return $out; }
        if (empty($map['state']) || ! isset($idx[$map['state']])) { fclose($fh); $out['error'] = 'No column is mapped to State.'; return $out; }
        $col = function (array $row, string $field) use ($idx, $map) {
            $h = $map[$field] ?? '';
            return ($h !== '' && isset($idx[$h])) ? trim((string) ($row[$idx[$h]] ?? '')) : '';
        };

        @set_time_limit(900);
        while (($row = fgetcsv($fh)) !== false) {
            $out['total']++;
            $shop  = $col($row, 'shop');
            $city  = $col($row, 'city');
            $addr  = $col($row, 'address');
            $state = self::stateCode($col($row, 'state'));
            if ($shop === '' || $state === '') { $out['blank']++; continue; }

            $exists = SalesProspect::query()
                ->whereRaw('LOWER(shop) = ?', [Str::lower($shop)])
                ->whereRaw('LOWER(COALESCE(city, "")) = ?', [Str::lower($city)])
                ->whereRaw('LOWER(COALESCE(address, "")) = ?', [Str::lower($addr)])
                ->exists();
            if ($exists) { $out['matched']++; continue; }

            $lat = $col($row, 'lat'); $lng = $col($row, 'lng');
            $hasCoords = is_numeric($lat) && is_numeric($lng);
            if ($hasCoords) $out['with_coords']++;
            $web  = $col($row, 'website');
            $host = $web ? strtolower((string) preg_replace('/^www\./', '', (string) parse_url(str_contains($web, '://') ? $web : "https://$web", PHP_URL_HOST))) : '';
            if ($host && in_array($host, self::NOT_A_SHOP_SITE, true)) $web = '';
            $ws = in_array(strtolower($col($row, 'workstand')), ['true', '1', 'yes', 'y'], true);

            $out['inserted']++;
            if ($dry) continue;

            $p = SalesProspect::create([
                'id'            => (string) Str::uuid(),
                'shop'          => mb_substr($shop, 0, 191),
                'city'          => $city ?: null,
                'state'         => $state,
                'postcode'      => mb_substr($col($row, 'postcode'), 0, 20) ?: null,
                'address'       => mb_substr($addr, 0, 255) ?: null,
                'lat'           => $hasCoords ? (float) $lat : null,
                'lng'           => $hasCoords ? (float) $lng : null,
                'website'       => mb_substr($web, 0, 255) ?: null,
                'phone'         => mb_substr($col($row, 'phone'), 0, 64) ?: null,
                'email'         => mb_substr($col($row, 'email'), 0, 191) ?: null,
                'owner_contact' => mb_substr($col($row, 'owner'), 0, 191) ?: null,
                'stage'         => 'prospect',
                'priority'      => $ws ? 'A' : 'B',
                'verified'      => false,
                'lead_score'    => 20 + ($ws ? 25 : 0) + ($web ? 10 : 0),
                'best_ask'      => '15-min owner/service-manager demo',
                'source'        => mb_substr($col($row, 'source'), 0, 120) ?: 'Shop list upload',
                'import_batch'  => $batch,
                'notes'         => $ws ? 'Verified dealer (per uploaded list)' : null,
            ]);
            if ($assign && TerritoryResolver::apply($p)) $out['assigned']++;
            if ($progress && $out['total'] % 500 === 0) $progress($out['total']);
        }
        fclose($fh);
        return $out;
    }

    /** "WA" → WA; "Washington" → WA; anything else → ''. */
    public static function stateCode(string $v): string
    {
        $v = trim($v);
        if (strlen($v) === 2 && ctype_alpha($v)) return strtoupper($v);
        $n = strtolower($v);
        return self::STATES[$n] ?? '';
    }

    public const STATES = ['alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA','colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE','district of columbia'=>'DC','florida'=>'FL','georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL','indiana'=>'IN','iowa'=>'IA','kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA','maine'=>'ME','maryland'=>'MD','massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN','mississippi'=>'MS','missouri'=>'MO','montana'=>'MT','nebraska'=>'NE','nevada'=>'NV','new hampshire'=>'NH','new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY','north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH','oklahoma'=>'OK','oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC','south dakota'=>'SD','tennessee'=>'TN','texas'=>'TX','utah'=>'UT','vermont'=>'VT','virginia'=>'VA','washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY'];

    /** Untouched = still a raw prospect nobody has contacted, scheduled, or converted. */
    public function untouchedQuery(string $batch)
    {
        return SalesProspect::query()->where('import_batch', $batch)
            ->where('stage', 'prospect')->whereNull('tenant_id')->whereNull('last_contacted_at')->whereNull('next_action_on')
            ->whereDoesntHave('activities', fn ($a) => $a->where('type', '!=', 'system'));
    }

    /** @return array{all:int, removed:int, kept:int} */
    public function undo(string $batch, bool $dry = false): array
    {
        $all = SalesProspect::query()->where('import_batch', $batch)->count();
        $q = $this->untouchedQuery($batch);
        $n = $q->count();
        if (! $dry) $q->chunkById(500, fn ($rows) => $rows->each->delete());
        return ['all' => $all, 'removed' => $n, 'kept' => $all - $n];
    }

    /** Batches still in the table, newest first, with how many rows remain and how many are still untouched. */
    public function batches(): array
    {
        $rows = SalesProspect::query()->whereNotNull('import_batch')
            ->select('import_batch', DB::raw('COUNT(*) total'), DB::raw('MIN(created_at) loaded_at'))
            ->groupBy('import_batch')->orderByDesc('loaded_at')->limit(12)->get();
        return $rows->map(fn ($r) => [
            'batch'     => $r->import_batch,
            'total'     => (int) $r->total,
            'untouched' => $this->untouchedQuery($r->import_batch)->count(),
            'loaded_at' => $r->loaded_at,
        ])->all();
    }
}
