<?php

namespace App\Console\Commands;

use App\Support\CatalogSpecs;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-OPTION-FIELDS: stores clean option values (casing, compound, bead,
 * TPI…) on each catalog row, for the register picker. Only rows in a
 * category some rule applies to, and only rows changed since they were last
 * read, unless --all. Saving rules in master admin › Option splitting
 * queues an --all run.
 */
class CatalogSpecAttrs extends Command
{
    protected $signature = 'catalog:spec-attrs {--all : re-read every matching row}';
    protected $description = 'Store clean option values (casing, compound…) on distributor catalog rows';

    public function handle(): int
    {
        try {
            $rules = CatalogSpecs::rules(true);
            $applies = array_values(array_unique(array_map(fn ($r) => $r['applies'], $rules)));
            if (! $rules) { $this->info('No active rules.'); return self::SUCCESS; }

            $q = DB::table('platform_distributor_catalogs');
            if (! in_array('', $applies, true)) {
                $q->where(function ($w) use ($applies) {
                    foreach ($applies as $a) { $w->orWhere('category_path', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $a) . '%'); }
                });
            }
            if (! $this->option('all')) {
                $q->where(fn ($w) => $w->whereNull('spec_attrs_at')->orWhereColumn('updated_at', '>', 'spec_attrs_at'));
            }

            $n = 0;
            $q->select(['id', 'display_name', 'category_path', 'source_raw'])
              ->chunkById(500, function ($rows) use (&$n) {
                  $now = now();
                  foreach ($rows as $r) {
                      $raw  = json_decode((string) $r->source_raw, true) ?: [];
                      $spec = CatalogSpecs::forRow($raw, (string) $r->display_name, (string) $r->category_path);
                      // spec_attrs_at is set a moment AFTER updated_at could be, so a
                      // row is not re-read every run; timestamps() would bump updated_at
                      DB::table('platform_distributor_catalogs')->where('id', $r->id)->update([
                          'spec_attrs'    => $spec ? json_encode($spec) : null,
                          'spec_attrs_at' => $now,
                      ]);
                      $n++;
                  }
              });
            $this->info("Read {$n} catalog rows.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            JobFailureReporter::report(self::class, 'Storing catalog option values failed', $e);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
