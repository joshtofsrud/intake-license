<?php
// MARKER-SALES-ROUTE

namespace App\Console\Commands;

use App\Models\SalesProspect;
use App\Services\Sales\TerritoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Load the Overture Maps shop export as prospects. Distinct from
 * intake:import-prospects (the Google Places pipeline CSV): different headers,
 * and the city comes from its own column rather than market/search_city.
 *
 * Expected headers: shop_name, address, city, state_code (or state), postcode,
 * website, domain, verified_workstand, source. Optional: latitude/longitude (or lat/lng).
 * Match key: name + city + address (case-insensitive) — 395 rows in the national
 * file share a name and city but only 61 also share an address.
 */
class ImportOvertureProspects extends Command
{
    protected $signature = 'intake:import-overture
        {path? : Path to the Overture CSV}
        {--batch= : Batch id to stamp on inserted rows (default: overture-YYYYMMDD-HHMM)}
        {--undo= : Remove every UNTOUCHED prospect from this batch id instead of importing}
        {--no-assign : Skip territory auto-assign}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Import the Overture Maps shop CSV as sales prospects (free base layer), or --undo a batch';

    /** Store locators and online retailers Overture sometimes attaches as a shop's website. */
    private const NOT_A_SHOP_SITE = ['chainreactioncycles.com', 'wiggle.com', 'amazon.com', 'trekbikes.com', 'specialized.com', 'giant-bicycles.com', 'cannondale.com', 'rei.com', 'walmart.com', 'target.com', 'ebay.com', 'jensonusa.com', 'competitivecyclist.com', 'backcountry.com'];

    public function handle(): int
    {
        if ($undo = $this->option('undo')) return $this->undo($undo);

        $path = $this->argument('path');
        if (! $path || ! is_file($path)) { $this->error('File not found: ' . ($path ?: '(none)')); return self::FAILURE; }
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        if (! $header) { $this->error('Empty CSV.'); return self::FAILURE; }
        $idx = array_flip(array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h))), $header));
        $get = fn (array $row, string ...$cols) => (function () use ($row, $cols, $idx) {
            foreach ($cols as $c) if (isset($idx[$c])) { $v = trim((string) ($row[$idx[$c]] ?? '')); if ($v !== '') return $v; }
            return '';
        })();
        if (! isset($idx['shop_name'])) { $this->error('No shop_name column — is this the Overture export?'); return self::FAILURE; }

        $dry   = (bool) $this->option('dry-run');
        $batch = $this->option('batch') ?: 'overture-' . now()->format('Ymd-Hi');
        $assign = ! $this->option('no-assign');
        $inserted = $matched = $blank = $total = $assigned = 0;

        $this->info(($dry ? '[dry run] ' : '') . "Batch $batch");
        while (($row = fgetcsv($fh)) !== false) {
            $total++;
            $shop = $get($row, 'shop_name', 'name');
            $city = $get($row, 'city', 'locality');
            $state = strtoupper($get($row, 'state_code'));
            if (strlen($state) !== 2) { $alt = strtoupper($get($row, 'state', 'region')); $state = strlen($alt) === 2 ? $alt : ''; } // full state names are not accepted
            $addr = $get($row, 'address', 'street');
            if ($shop === '' || $state === '') { $blank++; continue; }

            $exists = SalesProspect::query()
                ->whereRaw('LOWER(shop) = ?', [Str::lower($shop)])
                ->whereRaw('LOWER(COALESCE(city, "")) = ?', [Str::lower($city)])
                ->whereRaw('LOWER(COALESCE(address, "")) = ?', [Str::lower($addr)])
                ->exists();
            if ($exists) { $matched++; continue; }

            $lat = $get($row, 'latitude', 'lat'); $lng = $get($row, 'longitude', 'lng', 'lon');
            $web = $get($row, 'website');
            $host = $web ? strtolower((string) preg_replace('/^www\./', '', (string) parse_url(str_contains($web, '://') ? $web : "https://$web", PHP_URL_HOST))) : '';
            if ($host && in_array($host, self::NOT_A_SHOP_SITE, true)) $web = '';
            $ws = in_array(strtolower($get($row, 'verified_workstand')), ['true', '1', 'yes'], true);

            $inserted++;
            if ($dry) continue;

            $p = SalesProspect::create([
                'id'           => (string) Str::uuid(),
                'shop'         => mb_substr($shop, 0, 191),
                'city'         => $city ?: null,
                'state'        => mb_substr($state, 0, 2),
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
            if ($assign && TerritoryResolver::apply($p)) $assigned++;
            if ($total % 500 === 0) $this->line("  … $total rows");
        }
        fclose($fh);

        $this->newLine();
        $this->table(['Inserted', 'Already present', 'Skipped (blank)', 'Rows read', 'Assigned to a territory'], [[$inserted, $matched, $blank, $total, $assigned]]);
        if (! $dry) $this->info("Undo with: php artisan intake:import-overture --undo=$batch");
        return self::SUCCESS;
    }

    private function undo(string $batch): int
    {
        $q = SalesProspect::query()->where('import_batch', $batch);
        $all = (clone $q)->count();
        // untouched = nobody has worked it: still a raw prospect, no contact, no rep set by hand, not a tenant
        $untouched = (clone $q)->where('stage', 'prospect')->whereNull('tenant_id')->whereNull('last_contacted_at')->whereNull('next_action_on')
            ->whereDoesntHave('activities', fn ($a) => $a->where('type', '!=', 'system'));
        $n = $untouched->count();
        if ($this->option('dry-run')) { $this->info("[dry run] would remove $n of $all prospects in batch $batch"); return self::SUCCESS; }
        $untouched->chunkById(500, fn ($rows) => $rows->each->delete());
        $this->info("Removed $n of $all prospects in batch $batch (" . ($all - $n) . ' kept because someone has worked them).');
        return self::SUCCESS;
    }
}
