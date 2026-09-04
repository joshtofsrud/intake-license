<?php

namespace App\Filament\Pages;

use App\Models\AdminNavGroup;
use App\Models\AdminNavItem;
use App\Support\AdminNav;
use App\Support\AdminAccess;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-NAV-ARRANGE — arrange the sidebar without a deploy.
 *
 * Reads the live Filament registry rather than a stored list, so a page added
 * yesterday appears here today. Customisation rows are written only for items
 * you actually change; everything else keeps following its class.
 */
class NavArrange extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-bars-3';
    protected static ?string $navigationLabel = 'Sidebar';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?int    $navigationSort  = 80;
    protected static string  $view            = 'filament.pages.nav-arrange';
    protected static ?string $slug            = 'sidebar';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    // ---- what exists, right now -------------------------------------

    /** Every navigable class, from Filament itself — not from our table. */
    private function registered(): array
    {
        $out = [];

        foreach (Filament::getPanel('admin')->getPages() as $class) {
            $out[$class] = 'page';
        }
        foreach (Filament::getPanel('admin')->getResources() as $class) {
            $out[$class] = 'resource';
        }

        return array_filter($out, function ($kind, $class) {
            // Classes that opt out in code are not ours to arrange.
            try {
                $r = new \ReflectionClass($class);
                if ($r->hasProperty('shouldRegisterNavigation')) {
                    $p = $r->getProperty('shouldRegisterNavigation');
                    $p->setAccessible(true);
                    if ($p->getValue() === false) return false;
                }
            } catch (\Throwable $e) {
                return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function declared(string $class, string $prop)
    {
        try {
            $r = new \ReflectionClass($class);
            if (! $r->hasProperty($prop)) return null;
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            return $p->getValue();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Items grouped as they will appear, plus the hidden ones separately. */
    public function arrangement(): array
    {
        $rows   = AdminNav::items();
        $groups = [];
        $hidden = [];

        foreach ($this->registered() as $class => $kind) {
            $row = $rows[$class] ?? null;

            $label = ($row && $row->label)
                ? $row->label
                : ($this->declared($class, 'navigationLabel')
                    ?? $this->declared($class, 'title')
                    ?? class_basename($class));

            $group = ($row && $row->group)
                ? $row->group
                : ($this->declared($class, 'navigationGroup') ?? '(top level)');

            $entry = [
                'class'     => $class,
                'label'     => $label,
                'declared'  => $this->declared($class, 'navigationLabel')
                                ?? $this->declared($class, 'title')
                                ?? class_basename($class),
                'sort'      => $row ? $row->sort : ($this->declared($class, 'navigationSort') ?? 9999),
                'renamed'   => (bool) ($row && $row->label),
                'moved'     => (bool) ($row && $row->group),
                'short'     => class_basename($class),
            ];

            if ($row && $row->hidden) {
                $entry['group'] = $group;
                $hidden[] = $entry;
                continue;
            }

            $groups[$group][] = $entry;
        }

        foreach ($groups as $name => $items) {
            usort($items, fn ($a, $b) => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);
            $groups[$name] = $items;
        }

        // group order: stored, then anything new, alphabetically
        $stored = collect(AdminNav::groups())->sortBy('sort')->pluck('name')->all();
        $names  = array_keys($groups);
        usort($names, function ($a, $b) use ($stored) {
            $ia = array_search($a, $stored, true);
            $ib = array_search($b, $stored, true);
            if ($ia === false && $ib === false) return strcmp($a, $b);
            if ($ia === false) return 1;
            if ($ib === false) return -1;
            return $ia <=> $ib;
        });

        $ordered = [];
        foreach ($names as $n) $ordered[$n] = $groups[$n];

        usort($hidden, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return ['groups' => $ordered, 'hidden' => $hidden];
    }

    /** Shown on the page so a discrepancy is visible, not discovered later. */
    public function counts(): array
    {
        $a = $this->arrangement();
        $visible = array_sum(array_map('count', $a['groups']));

        return [
            'visible'    => $visible,
            'hidden'     => count($a['hidden']),
            'registered' => count($this->registered()),
        ];
    }

    public function groupNames(): array
    {
        return array_keys($this->arrangement()['groups']);
    }

    // ---- changes ----------------------------------------------------

    private function rowFor(string $class): AdminNavItem
    {
        return AdminNavItem::firstOrCreate(
            ['class' => $class],
            [
                'group'  => null,
                'label'  => null,
                'sort'   => $this->declared($class, 'navigationSort') ?? 9999,
                'hidden' => false,
            ]
        );
    }

    /** Moving an item writes sort for its WHOLE group, so order is unambiguous. */
    private function renumber(string $group): void
    {
        $items = $this->arrangement()['groups'][$group] ?? [];
        foreach (array_values($items) as $i => $entry) {
            $row = $this->rowFor($entry['class']);
            $row->update(['sort' => ($i + 1) * 10]);
        }
        AdminNav::forget();
    }

    public function moveUp(string $class, string $group): void
    {
        $this->nudge($class, $group, -1);
    }

    public function moveDown(string $class, string $group): void
    {
        $this->nudge($class, $group, 1);
    }

    private function nudge(string $class, string $group, int $dir): void
    {
        $this->renumber($group);

        $items = $this->arrangement()['groups'][$group] ?? [];
        $idx   = null;
        foreach ($items as $i => $e) {
            if ($e['class'] === $class) { $idx = $i; break; }
        }
        if ($idx === null) return;

        $swap = $idx + $dir;
        if ($swap < 0 || $swap >= count($items)) return;

        $a = $this->rowFor($items[$idx]['class']);
        $b = $this->rowFor($items[$swap]['class']);
        $tmp = $a->sort;
        $a->update(['sort' => $b->sort]);
        $b->update(['sort' => $tmp]);

        AdminNav::forget();
    }

    public function setGroup(string $class, string $group): void
    {
        $this->rowFor($class)->update([
            'group' => $group === '(top level)' ? null : $group,
            'sort'  => 9999,   // lands at the end of its new group
        ]);
        AdminNav::forget();

        Notification::make()->success()->title('Moved')->send();
    }

    public function rename(string $class, string $label): void
    {
        $label = trim($label);
        $this->rowFor($class)->update(['label' => $label === '' ? null : mb_substr($label, 0, 60)]);
        AdminNav::forget();

        Notification::make()->success()
            ->title($label === '' ? 'Back to its original name' : 'Renamed')
            ->send();
    }

    public function hide(string $class): void
    {
        $this->rowFor($class)->update(['hidden' => true]);
        AdminNav::forget();

        Notification::make()->success()->title('Hidden from the sidebar')
            ->body('The page still works if someone has the link — hiding tidies, it does not restrict.')
            ->send();
    }

    public function unhide(string $class): void
    {
        $this->rowFor($class)->update(['hidden' => false]);
        AdminNav::forget();

        Notification::make()->success()->title('Back in the sidebar')->send();
    }

    public function moveGroupUp(string $name): void   { $this->nudgeGroup($name, -1); }
    public function moveGroupDown(string $name): void { $this->nudgeGroup($name, 1); }

    private function nudgeGroup(string $name, int $dir): void
    {
        $names = $this->groupNames();
        foreach (array_values($names) as $i => $n) {
            AdminNavGroup::updateOrCreate(['name' => $n], ['sort' => ($i + 1) * 10]);
        }
        AdminNav::forget();

        $names = $this->groupNames();
        $idx = array_search($name, $names, true);
        if ($idx === false) return;
        $swap = $idx + $dir;
        if ($swap < 0 || $swap >= count($names)) return;

        $a = AdminNavGroup::where('name', $names[$idx])->first();
        $b = AdminNavGroup::where('name', $names[$swap])->first();
        if (! $a || ! $b) return;

        $tmp = $a->sort;
        $a->update(['sort' => $b->sort]);
        $b->update(['sort' => $tmp]);

        AdminNav::forget();
    }

    /** Everything back to what the code declares. */
    public function resetAll(): void
    {
        AdminNavItem::query()->delete();
        AdminNavGroup::query()->delete();
        AdminNav::forget();

        Notification::make()->success()
            ->title('Sidebar reset')
            ->body('Every item is back where its page declares it belongs.')
            ->send();
    }
}
