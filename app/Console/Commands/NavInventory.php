<?php

namespace App\Console\Commands;

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * MARKER-NAV-INVENTORY — every item the admin sidebar will render, listed.
 *
 * Written because nav items have disappeared before without anything failing.
 * A regrouping patch, a renamed class, a permission gate that got stricter —
 * none of these raise an error; the item is simply not there any more, and an
 * absence is the one thing you cannot spot by looking at a screen.
 *
 *   php artisan nav:inventory            list what is registered now
 *   php artisan nav:inventory --save     record it as the expected set
 *   php artisan nav:inventory --check    fail if it differs from the record
 *
 * --check is the one worth putting in the deploy script.
 */
class NavInventory extends Command
{
    protected $signature = 'nav:inventory
                            {--save : record the current set as expected}
                            {--check : exit non-zero if the set has changed}
                            {--json : machine-readable output}';

    protected $description = 'List every master-admin navigation item, and detect anything that moved or vanished';

    private const SNAPSHOT = 'nav-inventory.json';

    public function handle(): int
    {
        $items = $this->collect();

        if ($this->option('json')) {
            $this->line(json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($this->option('check')) {
            return $this->check($items);
        }

        if ($this->option('save')) {
            File::put(storage_path('app/' . self::SNAPSHOT), json_encode($items, JSON_PRETTY_PRINT));
            $this->info(count($items) . ' items recorded as the expected set.');
            return self::SUCCESS;
        }

        $this->render($items);
        return self::SUCCESS;
    }

    /**
     * Filament's own registry, not a grep of the source: this reports what the
     * panel will actually render.
     */
    private function collect(): array
    {
        $out = [];

        foreach (Filament::getPanel('admin')->getPages() as $class) {
            if (! $this->registers($class)) continue;
            $out[$this->key($class)] = [
                'label' => $this->labelOf($class),
                'group' => $this->prop($class, 'navigationGroup') ?? '(top level)',
                'sort'  => $this->prop($class, 'navigationSort'),
                'class' => $class,
                'kind'  => 'page',
            ];
        }

        foreach (Filament::getPanel('admin')->getResources() as $class) {
            if (! $this->registers($class)) continue;
            $out[$this->key($class)] = [
                'label' => $this->labelOf($class),
                'group' => $this->prop($class, 'navigationGroup') ?? '(top level)',
                'sort'  => $this->prop($class, 'navigationSort'),
                'class' => $class,
                'kind'  => 'resource',
            ];
        }

        ksort($out);
        return $out;
    }

    private function registers(string $class): bool
    {
        try {
            $r = new \ReflectionClass($class);
            if ($r->hasProperty('shouldRegisterNavigation')) {
                $p = $r->getProperty('shouldRegisterNavigation');
                $p->setAccessible(true);
                return (bool) $p->getValue();
            }
        } catch (\Throwable $e) {
            // A class that cannot even be reflected is worth reporting rather
            // than silently dropping — that is exactly how items disappear.
            $this->warn("could not inspect {$class}: " . $e->getMessage());
        }
        return true;
    }

    private function prop(string $class, string $name)
    {
        try {
            $r = new \ReflectionClass($class);
            if (! $r->hasProperty($name)) return null;
            $p = $r->getProperty($name);
            $p->setAccessible(true);
            return $p->getValue();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function labelOf(string $class): string
    {
        return $this->prop($class, 'navigationLabel')
            ?? $this->prop($class, 'title')
            ?? class_basename($class);
    }

    private function key(string $class): string
    {
        return $class;
    }

    private function render(array $items): void
    {
        $byGroup = [];
        foreach ($items as $i) {
            $byGroup[$i['group']][] = $i;
        }
        ksort($byGroup);

        foreach ($byGroup as $group => $rows) {
            usort($rows, fn ($a, $b) => ($a['sort'] ?? 999) <=> ($b['sort'] ?? 999));
            $this->newLine();
            $this->line("<fg=yellow>{$group}</> <fg=gray>(" . count($rows) . ')</>');
            foreach ($rows as $r) {
                $this->line(sprintf('   %-5s %-26s <fg=gray>%s</>',
                    $r['sort'] ?? '–', $r['label'], class_basename($r['class'])));
            }
        }

        $this->newLine();
        $this->info(count($items) . ' navigable items in ' . count($byGroup) . ' groups.');
        $this->line('<fg=gray>--save records this as expected; --check fails if it ever differs.</>');
    }

    private function check(array $items): int
    {
        $path = storage_path('app/' . self::SNAPSHOT);
        if (! File::exists($path)) {
            $this->error('No snapshot to compare against. Run: php artisan nav:inventory --save');
            return self::FAILURE;
        }

        $was = json_decode(File::get($path), true) ?: [];

        $gone    = array_diff_key($was, $items);
        $added   = array_diff_key($items, $was);
        $moved   = [];

        foreach (array_intersect_key($items, $was) as $k => $now) {
            if (($was[$k]['group'] ?? null) !== $now['group'] || ($was[$k]['label'] ?? null) !== $now['label']) {
                $moved[$k] = [$was[$k], $now];
            }
        }

        if (! $gone && ! $added && ! $moved) {
            $this->info(count($items) . ' items, unchanged.');
            return self::SUCCESS;
        }

        // MISSING is the serious one: something a person used is no longer
        // reachable, and nothing else would have told you.
        foreach ($gone as $k => $r) {
            $this->error("MISSING  {$r['label']}  ({$r['group']})  " . class_basename($k));
        }
        foreach ($moved as $k => [$before, $now]) {
            $this->warn("MOVED    {$before['label']} · {$before['group']} → {$now['group']}"
                . ($before['label'] !== $now['label'] ? "  (renamed to {$now['label']})" : ''));
        }
        foreach ($added as $k => $r) {
            $this->line("<fg=green>ADDED    {$r['label']}  ({$r['group']})</>");
        }

        $this->newLine();
        $this->line('If these changes are intended: php artisan nav:inventory --save');

        return $gone ? self::FAILURE : self::SUCCESS;   // additions and moves warn; losses fail
    }
}
