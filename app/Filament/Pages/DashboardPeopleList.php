<?php

namespace App\Filament\Pages;

use App\Models\DashboardMove;
use App\Support\AdminAccess;
use App\Support\Dashboard\DashboardPeople;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * MARKER-DASH-PEOPLE: who is on which dashboard. Each shop with its people,
 * what each person chose and what they actually see, and a way to move
 * people to another dashboard (with undo). Reached from the "Who's on what"
 * tab on Dashboards.
 */
class DashboardPeopleList extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dashboard-people';
    protected static ?string $title = 'Dashboards';
    protected static string $view = 'filament.pages.dashboard-people';

    #[Url(as: 'q', except: '')]
    public string $find = '';
    /** Only people who chose or see this dashboard. */
    #[Url(as: 'tpl', except: '')]
    public string $tpl = '';
    /** all | picked | default | fell */
    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    public int $limit = 20;
    /** Ticked people (ids). */
    public array $sel = [];
    public string $target = '';

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    private function guard(): bool
    {
        if ($this->canManage()) return true;
        Notification::make()->title('View access only')->body('Only admins with full Tenants access can move people.')->danger()->send();

        return false;
    }

    public function updatedFind(): void { $this->limit = 20; }
    public function updatedTpl(): void { $this->limit = 20; }
    public function updatedShow(): void { $this->limit = 20; }

    public function more(): void
    {
        $this->limit += 20;
    }

    public function showTemplate(string $key): void
    {
        $this->tpl = $key;
        $this->show = 'all';
        $this->limit = 20;
    }

    public function clearFilters(): void
    {
        $this->find = '';
        $this->tpl = '';
        $this->show = 'all';
        $this->limit = 20;
    }

    /** The rows that match the current search and filters. */
    private function matching(array $rows): array
    {
        $q = mb_strtolower(trim($this->find));

        return array_values(array_filter($rows, function ($r) use ($q) {
            if ($this->tpl !== '' && $r['effective'] !== $this->tpl && $r['pick'] !== $this->tpl) return false;
            if ($this->show !== 'all' && $r['status'] !== $this->show) return false;
            if ($q !== '' && ! str_contains(mb_strtolower($r['shop'] . ' ' . $r['name'] . ' ' . $r['email']), $q)) return false;

            return true;
        }));
    }

    public function toggleShop(string $tenantId): void
    {
        $ids = array_column(array_filter($this->matching(DashboardPeople::people()['rows']), fn ($r) => $r['tenant_id'] === $tenantId), 'id');
        $all = $ids && ! array_diff($ids, $this->sel);
        $this->sel = array_values($all ? array_diff($this->sel, $ids) : array_unique(array_merge($this->sel, $ids)));
    }

    public function selectMatching(): void
    {
        $ids = array_column($this->matching(DashboardPeople::people()['rows']), 'id');
        if (count($ids) > DashboardPeople::MAX_SELECT) {
            Notification::make()->title('That is more than one move can take')
                ->body('The first ' . number_format(DashboardPeople::MAX_SELECT) . ' are ticked. Narrow the list, or move the rest in a second go.')->warning()->send();
        }
        $this->sel = array_slice($ids, 0, DashboardPeople::MAX_SELECT);
    }

    public function clearSel(): void
    {
        $this->sel = [];
    }

    /** Opens the move dialog for the ticked people, or for everyone who chose one dashboard. */
    public function startMove(?string $fromKey = null): void
    {
        if (! $this->guard()) return;
        if ($fromKey !== null) {
            $this->sel = DashboardPeople::pickedIds($fromKey);
            if (count($this->sel) > DashboardPeople::MAX_SELECT) {
                $this->sel = array_slice($this->sel, 0, DashboardPeople::MAX_SELECT);
                Notification::make()->title('Moving the first ' . number_format(DashboardPeople::MAX_SELECT))
                    ->body('Do it again afterwards for the rest.')->warning()->send();
            }
        }
        if (! $this->sel) return;
        $default = DashboardPeople::defaultKey();
        $this->target = ($fromKey !== null && $fromKey !== $default) ? $default : '';
        $this->dispatch('open-modal', id: 'move-people');
    }

    public function move(): void
    {
        if (! $this->guard()) return;
        try {
            $plan = DashboardPeople::plan($this->sel, $this->target);
            if (! $plan['move']) {
                Notification::make()->title('Nobody to move')->body('Everyone ticked already has that dashboard, or their shop is not offered it.')->warning()->send();
                return;
            }
            $m = DashboardPeople::move($plan, $this->fromLabel($plan['from']), 'move', Auth::guard('web')->user());
        } catch (\Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
            if (! $e instanceof \InvalidArgumentException) report($e);
            return;
        }
        $n = $m->people;
        Notification::make()->title($n . ' ' . \Illuminate\Support\Str::plural('person', $n) . ' moved to ' . $m->to_name)
            ->body('They see it on their next page load. Undo is under Recent moves.')->success()->send();
        $this->sel = [];
        $this->target = '';
        $this->dispatch('close-modal', id: 'move-people');
    }

    public function undo(int $id): void
    {
        if (! $this->guard()) return;
        try {
            [$restored, $left] = DashboardPeople::undo(DashboardMove::findOrFail($id));
        } catch (\Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
            if (! $e instanceof \InvalidArgumentException) report($e);
            return;
        }
        Notification::make()->title($restored . ' ' . \Illuminate\Support\Str::plural('person', $restored) . ' put back')
            ->body($left ? $left . ' ' . ($left === 1 ? 'has' : 'have') . ' changed dashboard since, so ' . ($left === 1 ? 'was' : 'were') . ' left as they are now.' : null)
            ->success()->send();
    }

    /** "Studio (3), Overview (2)" for the record of a move. */
    private function fromLabel(array $from): string
    {
        $names = \App\Models\DashboardTemplate::pluck('name', 'key');
        arsort($from);

        return collect($from)->map(fn ($n, $k) => ($names[$k] ?? $k) . " ({$n})")->implode(', ');
    }

    protected function getViewData(): array
    {
        $data = DashboardPeople::people();
        $rows = $data['rows'];
        $templates = $data['templates'];
        $summary = DashboardPeople::summary($rows, $templates, $data['default']);

        $match = $this->matching($rows);
        $byShop = [];
        foreach ($match as $r) {
            $byShop[$r['tenant_id']]['shop'] = $r['shop'];
            $byShop[$r['tenant_id']]['plan'] = $r['plan'];
            $byShop[$r['tenant_id']]['people'][] = $r;
        }
        uasort($byShop, fn ($a, $b) => strcasecmp($a['shop'], $b['shop']));
        foreach ($byShop as &$shopRows) {
            usort($shopRows['people'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($shopRows);

        $preview = null;
        if ($this->sel && $this->target !== '') {
            try {
                $preview = DashboardPeople::plan($this->sel, $this->target);
            } catch (\Throwable $e) {
                $preview = null;
            }
        }
        $selRows = $this->sel ? array_filter($rows, fn ($r) => in_array($r['id'], $this->sel, true)) : [];

        return [
            'canManage' => $this->canManage(),
            'templates' => $templates,
            'default'   => $data['default'],
            'summary'   => $summary,
            'total'     => count($rows),
            'totalShops'=> count(array_unique(array_column($rows, 'tenant_id'))),
            'matchCount'=> count($match),
            'shops'     => array_slice($byShop, 0, $this->limit, true),
            'shopCount' => count($byShop),
            'selCount'  => count($selRows),
            'selShops'  => count(array_unique(array_column($selRows, 'tenant_id'))),
            'targets'   => $templates->filter(fn ($t) => in_array($t->state, ['live', 'beta'], true) && $t->live_version_id),
            'preview'   => $preview,
            'selNames'  => $this->selNames($selRows),
            'moves'     => DashboardMove::orderByDesc('id')->limit(8)->get(),
        ];
    }

    /** A few names for the dialog: "Maya, Sam and 4 more". */
    private function selNames(array $selRows): string
    {
        $names = array_slice(array_map(fn ($r) => $r['name'], array_values($selRows)), 0, 3);
        $more = count($selRows) - count($names);

        return implode(', ', $names) . ($more > 0 ? " and {$more} more" : '');
    }
}
