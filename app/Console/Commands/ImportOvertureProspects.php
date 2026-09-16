<?php
// MARKER-SALES-ROUTE · MARKER-SALES-UPLOAD — thin wrapper over ShopListImporter,
// which is the same code the Find shops upload uses.

namespace App\Console\Commands;

use App\Services\Sales\ShopListImporter;
use Illuminate\Console\Command;

class ImportOvertureProspects extends Command
{
    protected $signature = 'intake:import-overture
        {path? : Path to the Overture CSV}
        {--batch= : Batch id to stamp on inserted rows (default: overture-YYYYMMDD-HHMM)}
        {--undo= : Remove every UNTOUCHED prospect from this batch id instead of importing}
        {--no-assign : Skip territory auto-assign}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Import the Overture Maps shop CSV as sales prospects (free base layer), or --undo a batch';

    public function handle(ShopListImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($undo = $this->option('undo')) {
            $r = $importer->undo($undo, $dry);
            $this->info(($dry ? '[dry run] would remove' : 'Removed') . " {$r['removed']} of {$r['all']} prospects in batch $undo ({$r['kept']} kept because someone has worked them).");
            return self::SUCCESS;
        }

        $path = $this->argument('path');
        if (! $path || ! is_file($path)) { $this->error('File not found: ' . ($path ?: '(none)')); return self::FAILURE; }

        $r = $importer->import($path, $this->option('batch') ?: null, ! $this->option('no-assign'), $dry, fn ($n) => $this->line("  … $n rows"));
        if ($r['error']) { $this->error($r['error']); return self::FAILURE; }

        $this->newLine();
        $this->info(($dry ? '[dry run] ' : '') . "Batch {$r['batch']}");
        $this->table(['Inserted', 'Already present', 'Skipped (blank)', 'Rows read', 'With coordinates', 'Assigned to a territory'],
            [[$r['inserted'], $r['matched'], $r['blank'], $r['total'], $r['with_coords'], $r['assigned']]]);
        if (! $dry) $this->info("Undo with: php artisan intake:import-overture --undo={$r['batch']}");
        return self::SUCCESS;
    }
}
