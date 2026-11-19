<?php

namespace App\Console\Commands;

use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleComposer;
use Illuminate\Console\Command;

/**
 * Rebuild display_name / display_subtitle / search_text on existing catalog
 * rows by re-running the composer over ALREADY-STORED fields. No HLC re-pull.
 *
 *   php artisan distributor:recompose HLC      # one distributor
 *   php artisan distributor:recompose          # all
 */
class RecomposeDistributorTitles extends Command
{
    protected $signature = 'distributor:recompose {code? : distributor code, omit for all}';
    protected $description = 'Recompose catalog titles + search blob from stored fields';

    public function handle(CatalogTitleComposer $composer): int
    {
        $code = $this->argument('code');
        $q = PlatformDistributorCatalog::query();
        if ($code) { $q->where('distributor_code', $code); }

        $total = (clone $q)->count();
        $this->info("Recomposing {$total} rows" . ($code ? " for {$code}" : '') . '…');
        $bar = $this->output->createProgressBar($total);

        $n = 0;
        $q->chunkById(500, function ($rows) use ($composer, &$n, $bar) {
            foreach ($rows as $r) {
                // the same parts the Titles preview uses, so stored titles match it
                $c = $composer->compose($r->distributor_code, $composer->partsFromRow($r));
                $r->forceFill([
                    'display_name'     => $c['title'] !== '' ? $c['title'] : $r->name,
                    'display_subtitle' => $c['subtitle'] !== '' ? $c['subtitle'] : null,
                    'search_text'      => ($c['search'] ?? '') !== '' ? $c['search'] : null,
                ])->saveQuietly();
                $n++; $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Recomposed {$n} rows.");
        return self::SUCCESS;
    }
}
