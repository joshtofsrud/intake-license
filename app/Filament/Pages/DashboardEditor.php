<?php

namespace App\Filament\Pages;

use App\Models\DashboardBlock;
use App\Models\DashboardTemplate;
use App\Models\DashboardTemplateVersion;
use App\Support\AdminAccess;
use App\Models\Tenant;
use App\Support\Dashboard\Audience;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\Layout;
use App\Support\Dashboard\TemplateStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * MARKER-DASH-ADMIN: edit one dashboard template — its rows, columns and
 * blocks — as a draft, then publish it. Opened from the Dashboards page.
 */
class DashboardEditor extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dashboard-editor';
    protected static ?string $title = 'Edit dashboard';
    protected static string $view = 'filament.pages.dashboard-editor';

    #[Url]
    public string $t = '';

    /** The layout on screen. Saved only by Save draft or Publish. */
    public array $rows = [];

    /** Where Add puts a block: [row, column]. */
    public array $target = [0, 0];

    public string $name = '';
    public string $label = '';
    public bool $beta = false;
    public string $note = '';
    public string $rearrange = 'none';

    /** The placed block whose settings are open: [row, column, index], or empty. */
    public array $sel = [];

    // who gets it (saved on its own, straight away)
    public string $audMode = 'all';
    public string $audPlan = '';
    public array $audIndustries = [];
    public array $audAddons = [];
    public array $audInclude = [];
    public array $audExclude = [];
    public string $shopFind = '';

    public function mount(): void
    {
        $tpl = DashboardTemplate::where('key', $this->t)->first();
        if (! $tpl) {
            $this->redirect(Dashboards::getUrl());
            return;
        }
        $this->load($tpl);
    }

    private function load(DashboardTemplate $tpl): void
    {
        $this->rows = TemplateStore::workingLayout($tpl);
        $this->name = $tpl->name;
        $this->label = (string) ($tpl->switch_label ?: $tpl->name);
        $this->beta = (bool) $tpl->beta_badge;
        $this->rearrange = in_array($tpl->rearrange, ['none', 'hide', 'both'], true) ? $tpl->rearrange : 'none';
        $a = Audience::normalize($tpl->audience);
        $this->audMode = $a['mode'];
        $this->audPlan = (string) $a['plan'];
        $this->audIndustries = $a['industries'];
        $this->audAddons = $a['addons'];
        $this->audInclude = $a['include'];
        $this->audExclude = $a['exclude'];
        $this->target = [max(0, count($this->rows) - 1), 0];
    }

    public function getTitle(): string
    {
        return $this->name !== '' ? $this->name : 'Edit dashboard';
    }

    private function tpl(): DashboardTemplate
    {
        return TemplateStore::find($this->t);
    }

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    private function guard(): bool
    {
        if ($this->canManage() && $this->tpl()->state !== 'retired') return true;
        Notification::make()->title($this->canManage() ? 'Restore this template before editing it.' : 'View access only')->danger()->send();

        return false;
    }

    private function fail(\Throwable $e): void
    {
        Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
        if (! $e instanceof \InvalidArgumentException) report($e);
    }

    // ---- layout edits (on screen only) ---------------------------------

    private function settle(): void
    {
        $this->rows = Layout::sanitize($this->rows);
        $r = min($this->target[0] ?? 0, max(0, count($this->rows) - 1));
        $c = min($this->target[1] ?? 0, max(0, count($this->rows[$r]['split'] ?? [12]) - 1));
        $this->target = [$r, $c];
        if ($this->sel && ! isset($this->rows[$this->sel[0]]['cols'][$this->sel[1]][$this->sel[2]])) {
            $this->sel = [];
        }
    }

    // ---- one block's settings (part of the layout: saved with the draft) --

    public function selectBlock(int $r, int $c, int $i): void
    {
        $this->sel = isset($this->rows[$r]['cols'][$c][$i]) && $this->sel !== [$r, $c, $i] ? [$r, $c, $i] : [];
    }

    public function toggleLock(): void
    {
        if (! $this->guard() || ! $this->sel) return;
        [$r, $c, $i] = $this->sel;
        $this->rows[$r]['cols'][$c][$i]['lock'] = empty($this->rows[$r]['cols'][$c][$i]['lock']);
        $this->settle();
    }

    public function addRow(int $splitIndex): void
    {
        if (! $this->guard()) return;
        $split = Layout::SPLITS[$splitIndex] ?? [12];
        $this->rows[] = ['split' => $split, 'cols' => array_fill(0, count($split), [])];
        $this->target = [count($this->rows) - 1, 0];
        $this->settle();
    }

    public function removeRow(int $r): void
    {
        $this->sel = [];
        if (! $this->guard() || ! isset($this->rows[$r])) return;
        array_splice($this->rows, $r, 1);
        if (! $this->rows) $this->rows = [['split' => [12], 'cols' => [[]]]];
        $this->settle();
    }

    public function moveRow(int $r, int $dir): void
    {
        $this->sel = [];
        if (! $this->guard()) return;
        $n = $r + $dir;
        if (! isset($this->rows[$r], $this->rows[$n])) return;
        [$this->rows[$r], $this->rows[$n]] = [$this->rows[$n], $this->rows[$r]];
        $this->target = [$n, 0];
        $this->settle();
    }

    /** Fewer columns: blocks from the dropped columns move into the last one. Nothing is removed. */
    public function setSplit(int $r, int $splitIndex): void
    {
        $this->sel = [];
        if (! $this->guard() || ! isset($this->rows[$r])) return;
        $split = Layout::SPLITS[$splitIndex] ?? [12];
        $old = $this->rows[$r]['cols'];
        $cols = [];
        foreach ($split as $i => $_) $cols[$i] = $old[$i] ?? [];
        for ($i = count($split); $i < count($old); $i++) {
            $cols[count($split) - 1] = array_merge($cols[count($split) - 1], $old[$i]);
        }
        $this->rows[$r] = ['split' => $split, 'cols' => $cols];
        $this->settle();
    }

    public function setTarget(int $r, int $c): void
    {
        $this->target = [$r, $c];
        $this->settle();
    }

    public function addBlock(string $key): void
    {
        if (! $this->guard()) return;
        if (! isset(BlockRegistry::builtIns()[$key]) || in_array($key, Layout::blockKeys($this->rows), true)) return;
        [$r, $c] = $this->target;
        if (! isset($this->rows[$r]['cols'][$c])) { $r = 0; $c = 0; }
        $this->rows[$r]['cols'][$c][] = ['b' => $key];
        $this->settle();
    }

    public function removeBlock(int $r, int $c, int $i): void
    {
        $this->sel = [];
        if (! $this->guard() || ! isset($this->rows[$r]['cols'][$c][$i])) return;
        array_splice($this->rows[$r]['cols'][$c], $i, 1);
        $this->settle();
    }

    /** Drag and drop, and the arrow buttons: move a placed block, or add one from the list. */
    public function dropAt(array $from, int $r, int $c, int $at): void
    {
        if (! $this->guard() || ! isset($this->rows[$r]['cols'][$c])) return;
        if (isset($from['add'])) {
            $key = (string) $from['add'];
            if (! isset(BlockRegistry::builtIns()[$key]) || in_array($key, Layout::blockKeys($this->rows), true)) return;
            $p = ['b' => $key];
        } else {
            [$fr, $fc, $fi] = [(int) ($from['r'] ?? -1), (int) ($from['c'] ?? -1), (int) ($from['i'] ?? -1)];
            if (! isset($this->rows[$fr]['cols'][$fc][$fi])) return;
            $p = $this->rows[$fr]['cols'][$fc][$fi];
            array_splice($this->rows[$fr]['cols'][$fc], $fi, 1);
            if ($fr === $r && $fc === $c && $fi < $at) $at--;
        }
        $at = max(0, min($at, count($this->rows[$r]['cols'][$c])));
        array_splice($this->rows[$r]['cols'][$c], $at, 0, [$p]);
        $this->target = [$r, $c];
        $this->sel = [$r, $c, $at];
        $this->settle();
    }

    public function nudge(int $r, int $c, int $i, int $dir): void
    {
        $this->dropAt(['r' => $r, 'c' => $c, 'i' => $i], $r, $c, $i + ($dir > 0 ? 2 : -1));
    }

    public function toColumn(int $r, int $c, int $i, int $toC): void
    {
        if (! isset($this->rows[$r]['cols'][$toC])) return;
        $this->dropAt(['r' => $r, 'c' => $c, 'i' => $i], $r, $toC, count($this->rows[$r]['cols'][$toC]));
    }

    // ---- saving ---------------------------------------------------------

    public function saveDraft(): void
    {
        if (! $this->guard()) return;
        try {
            TemplateStore::saveDraft($this->t, $this->rows);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        Notification::make()->title('Draft saved')->body('Shops still see the live version.')->success()->send();
    }

    public function discardDraft(): void
    {
        if (! $this->guard()) return;
        $tpl = TemplateStore::discardDraft($this->t);
        $this->rows = TemplateStore::workingLayout($tpl);
        $this->settle();
        Notification::make()->title('Draft discarded')->success()->send();
    }

    public function revertScreen(): void
    {
        $this->rows = TemplateStore::workingLayout($this->tpl());
        $this->settle();
    }

    public function publish(): void
    {
        if (! $this->guard()) return;
        try {
            $v = TemplateStore::publish($this->t, $this->rows, $this->note, Auth::guard('web')->id());
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        $this->note = '';
        $this->rows = TemplateStore::workingLayout($this->tpl());
        $this->dispatch('close-modal', id: 'publish-dashboard');
        Notification::make()->title($this->name . ' v' . $v->version . ' is live')
            ->body('Everyone on it sees it on their next page load.')->success()->send();
    }

    public function openVersion(int $versionId): void
    {
        if (! $this->guard()) return;
        try {
            $tpl = TemplateStore::openVersion($this->t, $versionId);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        $this->rows = TemplateStore::workingLayout($tpl);
        $this->settle();
        $v = DashboardTemplateVersion::find($versionId);
        Notification::make()->title('v' . $v?->version . ' is now your draft')->body('Shops still see the live version until you publish.')->success()->send();
    }

    public function saveSettings(): void
    {
        if (! $this->guard()) return;
        try {
            TemplateStore::saveSettings($this->t, $this->name, $this->label, $this->beta, $this->rearrange);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        Notification::make()->title('Settings saved')->body('The switch shows the new label on the next page load.')->success()->send();
    }

    // ---- who gets it ------------------------------------------------------

    private function audience(): array
    {
        return Audience::normalize([
            'mode' => $this->audMode, 'plan' => $this->audPlan ?: null, 'industries' => $this->audIndustries,
            'addons' => $this->audAddons, 'include' => $this->audInclude, 'exclude' => $this->audExclude,
        ]);
    }

    public function addShop(string $list, string $id): void
    {
        if (! in_array($list, ['audInclude', 'audExclude'], true) || ! Tenant::whereKey($id)->exists()) return;
        $this->{$list} = array_values(array_unique(array_merge($this->{$list}, [$id])));
        $other = $list === 'audInclude' ? 'audExclude' : 'audInclude';
        $this->{$other} = array_values(array_diff($this->{$other}, [$id]));
        $this->shopFind = '';
    }

    public function removeShop(string $list, string $id): void
    {
        if (! in_array($list, ['audInclude', 'audExclude'], true)) return;
        $this->{$list} = array_values(array_diff($this->{$list}, [$id]));
    }

    public function saveAudience(): void
    {
        if (! $this->guard()) return;
        try {
            $tpl = TemplateStore::saveAudience($this->t, $this->audience());
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        Notification::make()->title('Saved: ' . Audience::describe($tpl->audience))
            ->body($tpl->state === 'live' ? 'Shops that no longer get it fall back to the default on their next load.' : 'It takes effect when you publish.')
            ->success()->send();
    }

    protected function getViewData(): array
    {
        $tpl = $this->tpl();
        $stored = TemplateStore::workingLayout($tpl);
        $screen = Layout::sanitize($this->rows);
        $names = TemplateStore::blockNames();
        $hidden = DashboardBlock::whereNotNull('hidden_at')->pluck('key')->all();
        $on = Layout::blockKeys($screen);

        $catalog = collect(BlockRegistry::builtIns())->map(fn ($d, $k) => [
            'key' => $k, 'name' => $names[$k] ?? $d['name'], 'origin' => ucfirst($d['origin']),
            'desc' => $d['desc'], 'on' => in_array($k, $on, true), 'hidden' => in_array($k, $hidden, true),
        ])->groupBy('origin');

        $picks = TemplateStore::pickCounts();

        return [
            'tpl'        => $tpl,
            'canEdit'    => $this->canManage() && $tpl->state !== 'retired',
            'dirty'      => json_encode($screen) !== json_encode($stored),
            'hasDraft'   => is_array($tpl->draft_layout),
            'changes'    => TemplateStore::changes($tpl, $screen),
            'canPublish' => TemplateStore::canPublish($tpl, $screen),
            'catalog'    => $catalog,
            'names'      => $names,
            'hidden'     => $hidden,
            'registry'   => BlockRegistry::builtIns(),
            'splits'     => Layout::SPLITS,
            'versions'   => $tpl->versions()->orderByDesc('version')->limit(12)->get(),
            'picked'     => (int) ($picks[$tpl->key] ?? 0),
            'selBlock'   => $this->sel ? ($screen[$this->sel[0]]['cols'][$this->sel[1]][$this->sel[2]] ?? null) : null,
            'customizeOn' => \App\Support\Dashboard\DashboardTemplates::customizeEnabled(),
            'aud'        => $this->audience(),
            'audSaved'   => Audience::normalize($tpl->audience),
            'audCount'   => Audience::countMatching($this->audience()),
            'plans'      => Audience::PLANS,
            'industries' => collect((array) config('industry_packs', []))->map(fn ($v) => $v['label'] ?? '')->all(),
            'addons'     => \Illuminate\Support\Facades\DB::table('addons')->whereIn('status', ['active', 'deprecated'])->orderBy('sort_order')->pluck('name', 'code')->all(),
            'shopNames'  => Tenant::whereIn('id', array_merge($this->audInclude, $this->audExclude))->pluck('name', 'id')->all(),
            'shopHits'   => strlen(trim($this->shopFind)) >= 2
                ? Tenant::where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($this->shopFind)) . '%')->orderBy('name')->limit(8)->pluck('name', 'id')->all()
                : [],
        ];
    }
}
