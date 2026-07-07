<?php
// MARKER-SALES-IMPORT — Import the Google Places pipeline output into sales_prospects.
//
//   php artisan intake:import-prospects output/intake_bike_shop_prospects_master.csv
//   php artisan intake:import-prospects master.csv --operational-only --dry-run
//
// Idempotent and progress-safe:
//  - Identity is google_place_id. If a row's place id already exists, we ENRICH it.
//  - If there's no place id match, we fall back to (shop, city, state) so the
//    national run merges into your hand-built WA rows instead of duplicating them
//    (and backfills their place id / phone / website on the way through).
//  - On an existing row we only refresh DISCOVERY columns (status, rating, geo,
//    maps url, address, place id). Stage, next action, tenant link, verified flag,
//    priority, lead score, and your notes are human-owned and never overwritten.

namespace App\Console\Commands;

use App\Models\SalesProspect;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportProspects extends Command
{
    protected $signature = 'intake:import-prospects
        {path : Path to the deduped master CSV from the pipeline}
        {--operational-only : Skip shops Google marks CLOSED_TEMPORARILY / CLOSED_PERMANENTLY}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Import / enrich sales prospects from the Google Places pipeline master CSV';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("Could not open: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            $this->error('Empty CSV.');
            fclose($fh);
            return self::FAILURE;
        }
        $idx = array_flip(array_map(fn ($h) => trim((string) $h), $header));

        $get = function (array $row, string $col) use ($idx) {
            return isset($idx[$col]) ? trim((string) ($row[$idx[$col]] ?? '')) : '';
        };

        $dry        = (bool) $this->option('dry-run');
        $opOnly     = (bool) $this->option('operational-only');
        $inserted   = $enriched = $unchanged = $skippedClosed = $skippedBlank = $total = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $total++;

            $shop  = $get($row, 'shop_name');
            $city  = $get($row, 'market') ?: $get($row, 'search_city');
            $state = $get($row, 'state');
            if ($shop === '') { $skippedBlank++; continue; }

            $status = $get($row, 'business_status');
            if ($opOnly && $status !== '' && $status !== 'OPERATIONAL') {
                $skippedClosed++;
                continue;
            }

            $placeId = $get($row, 'google_place_id');

            // Discovery payload — Google's current truth about the shop.
            $discovery = array_filter([
                'business_status' => $status ?: null,
                'rating'          => $get($row, 'rating') !== '' ? (float) $get($row, 'rating') : null,
                'rating_count'    => $get($row, 'rating_count') !== '' ? (int) $get($row, 'rating_count') : null,
                'lat'             => $get($row, 'latitude') !== '' ? (float) $get($row, 'latitude') : null,
                'lng'             => $get($row, 'longitude') !== '' ? (float) $get($row, 'longitude') : null,
                'google_maps_url' => $get($row, 'google_maps_url') ?: null,
                'primary_type'    => $get($row, 'primary_type') ?: null,
                'address'         => $get($row, 'address') ?: null,
                'google_place_id' => $placeId ?: null,
                'state'           => $state ?: null,
                'route_loop'      => $get($row, 'route_loop') ?: null,
            ], fn ($v) => $v !== null);

            // These are backfill-only on an existing row (fill if empty, never clobber).
            $backfillOnlyCols = ['google_place_id', 'address', 'state', 'route_loop'];

            // Match: place id first, then (shop, city) to fold into hand-built rows.
            // We deliberately don't require state equality here, because the original
            // WA rows were seeded before the state column existed (state is NULL on
            // them) — matching on shop+city lets the national run enrich them and
            // backfill their state/place id instead of creating duplicates.
            $existing = null;
            if ($placeId !== '') {
                $existing = SalesProspect::where('google_place_id', $placeId)->first();
            }
            if (! $existing) {
                $existing = SalesProspect::query()
                    ->whereRaw('LOWER(shop) = ?', [Str::lower($shop)])
                    ->whereRaw('LOWER(COALESCE(city, "")) = ?', [Str::lower($city)])
                    ->first();
            }

            if ($existing) {
                $changes = [];
                foreach ($discovery as $col => $val) {
                    if (in_array($col, $backfillOnlyCols, true) && filled($existing->{$col})) {
                        continue;
                    }
                    if ((string) $existing->{$col} !== (string) $val) {
                        $changes[$col] = $val;
                    }
                }
                // Backfill empty contact fields without ever overwriting typed ones.
                foreach (['phone' => $get($row, 'phone'), 'website' => $get($row, 'website')] as $col => $val) {
                    if ($val !== '' && blank($existing->{$col})) {
                        $changes[$col] = $val;
                    }
                }

                if ($changes) {
                    if (! $dry) { $existing->update($changes); }
                    $enriched++;
                } else {
                    $unchanged++;
                }
                continue;
            }

            // New prospect.
            if (! $dry) {
                SalesProspect::create(array_merge($discovery, [
                    'id'          => (string) Str::uuid(),
                    'shop'        => $shop,
                    'city'        => $city ?: null,
                    'state'       => $state ?: null,
                    'route_loop'  => $get($row, 'route_loop') ?: null,
                    'priority'    => in_array($get($row, 'priority'), ['A','B','C','D'], true) ? $get($row, 'priority') : 'B',
                    'lead_score'  => $get($row, 'score') !== '' ? (int) $get($row, 'score') : 0,
                    'verified'    => false,                       // Places discovery != human verification
                    'phone'       => $get($row, 'phone') ?: null,
                    'website'     => $get($row, 'website') ?: null,
                    'best_ask'    => '15-min owner/service-manager demo',
                    'source'      => $get($row, 'source_primary') ?: 'Google Places',
                    'source_url'  => $get($row, 'source_url') ?: null,
                    'notes'       => trim('Imported via Google Places. ' . $get($row, 'score_notes')),
                ]));
            }
            $inserted++;
        }
        fclose($fh);

        $this->newLine();
        $this->table(
            ['Inserted', 'Enriched', 'Unchanged', 'Skipped (closed)', 'Skipped (blank)', 'Rows read'],
            [[$inserted, $enriched, $unchanged, $skippedClosed, $skippedBlank, $total]],
        );
        $this->info($dry
            ? 'Dry run — nothing written. Re-run without --dry-run to apply.'
            : 'Import complete.');

        return self::SUCCESS;
    }
}
