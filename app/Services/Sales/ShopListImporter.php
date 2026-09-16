<?php
// MARKER-SALES-UPLOAD — one importer for the Overture shop CSV, used by the
// browser upload on Find shops and by intake:import-overture.

namespace App\Services\Sales;

use App\Models\SalesProspect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopListImporter
{
    /** Store locators and online retailers Overture sometimes attaches as a shop's website. */
    public const NOT_A_SHOP_SITE = ['chainreactioncycles.com', 'wiggle.com', 'amazon.com', 'trekbikes.com', 'specialized.com', 'giant-bicycles.com', 'cannondale.com', 'rei.com', 'walmart.com', 'target.com', 'ebay.com', 'jensonusa.com', 'competitivecyclist.com', 'backcountry.com'];

    /**
     * @return array{inserted:int, matched:int, blank:int, total:int, assigned:int, with_coords:int, batch:string, error:?string}
     */
    public function import(string $path, ?string $batch = null, bool $assign = true, bool $dry = false, ?callable $progress = null): array
    {
        $batch ??= 'overture-' . now()->format('Ymd-Hi');
        $out = ['inserted' => 0, 'matched' => 0, 'blank' => 0, 'total' => 0, 'assigned' => 0, 'with_coords' => 0, 'batch' => $batch, 'error' => null];

        $fh = is_file($path) ? fopen($path, 'r') : false;
        if (! $fh) { $out['error'] = 'File not found or unreadable.'; return $out; }
        $header = fgetcsv($fh);
        if (! $header) { fclose($fh); $out['error'] = 'Empty CSV.'; return $out; }
        $idx = array_flip(array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header));
        if (! isset($idx['shop_name']) && ! isset($idx['name'])) { fclose($fh); $out['error'] = 'No shop_name column — is this the shop list export?'; return $out; }
        $get = function (array $row, string ...$cols) use ($idx) {
            foreach ($cols as $c) if (isset($idx[$c])) { $v = trim((string) ($row[$idx[$c]] ?? '')); if ($v !== '') return $v; }
            return '';
        };

        @set_time_limit(900);
        while (($row = fgetcsv($fh)) !== false) {
            $out['total']++;
            $shop  = $get($row, 'shop_name', 'name');
            $city  = $get($row, 'city', 'locality');
            $addr  = $get($row, 'address', 'street');
            $state = strtoupper($get($row, 'state_code'));
            if (strlen($state) !== 2) { $alt = strtoupper($get($row, 'state', 'region')); $state = strlen($alt) === 2 ? $alt : ''; }
            if ($shop === '' || $state === '') { $out['blank']++; continue; }

            $exists = SalesProspect::query()
                ->whereRaw('LOWER(shop) = ?', [Str::lower($shop)])
                ->whereRaw('LOWER(COALESCE(city, "")) = ?', [Str::lower($city)])
                ->whereRaw('LOWER(COALESCE(address, "")) = ?', [Str::lower($addr)])
                ->exists();
            if ($exists) { $out['matched']++; continue; }

            $lat = $get($row, 'latitude', 'lat'); $lng = $get($row, 'longitude', 'lng', 'lon');
            if ($lat !== '' && $lng !== '') $out['with_coords']++;
            $web  = $get($row, 'website');
            $host = $web ? strtolower((string) preg_replace('/^www\./', '', (string) parse_url(str_contains($web, '://') ? $web : "https://$web", PHP_URL_HOST))) : '';
            if ($host && in_array($host, self::NOT_A_SHOP_SITE, true)) $web = '';
            $ws = in_array(strtolower($get($row, 'verified_workstand')), ['true', '1', 'yes'], true);

            $out['inserted']++;
            if ($dry) continue;

            $p = SalesProspect::create([
                'id'           => (string) Str::uuid(),
                'shop'         => mb_substr($shop, 0, 191),
                'city'         => $city ?: null,
                'state'        => $state,
                'postcode'     => $get($row, 'postcode', 'zip') ?: null,
                'address'      => $addr ?: null,
                'lat'          => $lat !== '' ? (float) $lat : null,
                'lng'          => $lng !== '' ? (float) $lng : null,
                'website'      => $web ?: null,
                'stage'        => 'prospect',
                'priority'     => $ws ? 'A' : 'B',
                'verified'     => false,
                'lead_score'   => 20 + ($ws ? 25 : 0) + ($web ? 10 : 0),
                'best_ask'     => '15-min owner/service-manager demo',
                'source'       => $get($row, 'source') ?: 'Overture Maps',
                'import_batch' => $batch,
                'notes'        => $ws ? 'Workstand-verified dealer (Overture)' : null,
            ]);
            if ($assign && TerritoryResolver::apply($p)) $out['assigned']++;
            if ($progress && $out['total'] % 500 === 0) $progress($out['total']);
        }
        fclose($fh);
        return $out;
    }

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
