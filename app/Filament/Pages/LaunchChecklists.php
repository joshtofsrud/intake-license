<?php

namespace App\Filament\Pages;

use App\Models\Launch\LaunchChecklist;
use App\Models\Launch\LaunchStep;
use App\Models\Launch\TenantLaunchEvent;
use App\Models\Launch\TenantLaunchState;
use App\Models\Tenant;
use App\Support\AdminAccess;
use App\Support\Launch\Launch;
use App\Support\Launch\LaunchChecks;
use App\Support\Launch\LaunchDefaults;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * MARKER-LAUNCH-CHECKLIST: master admin for launch checklists.
 *
 *   Checklist templates     how each module's checklist behaves, and its steps (explicit Save)
 *   One shop                a shop's checklists: waive, let it go live, pause, re-run, history
 *   Where shops get stuck   read-only, across every shop with the module on
 */
class LaunchChecklists extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-rocket-launch';
    protected static ?string $navigationLabel = 'Launch checklists';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 20;
    protected static string  $view            = 'filament.pages.launch-checklists';
    protected static ?string $slug            = 'launch-checklists';
    protected static ?string $title           = 'Launch checklists';

    #[Url(as: 'view')] public string $tab = 'templates';
    #[Url] public string $module = 'ecom';
    #[Url(as: 'tenant')] public ?string $shopId = null;

    public array $behaviour = [];
    public array $steps = [];
    public bool $dirty = false;

    // modal fields
    public ?int $editing = null;
    public array $edit = ['title' => '', 'group_name' => '', 'explain' => '', 'how' => ''];
    public array $add = ['title' => '', 'group_name' => '', 'explain' => '', 'check' => 'confirm', 'level' => 'rec'];
    public string $reason = '';
    public ?string $target = null; // step key for waive / reset
    public ?string $nudgeFor = null; // a shop picked from "where shops get stuck"
    public string $shopSearch = '';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! in_array($this->tab, ['templates', 'shop', 'stuck'], true)) $this->tab = 'templates';
        if (! isset(LaunchDefaults::MODULES[$this->module])) $this->module = 'ecom';
        $this->loadTemplate();
    }

    public function getTitle(): string
    {
        return match ($this->tab) {
            'shop'  => $this->shop()?->name ?? 'Launch checklist for one shop',
            'stuck' => 'Where shops get stuck',
            default => 'Launch checklists',
        };
    }

    public function getSubheading(): ?string
    {
        return match ($this->tab) {
            'shop'  => $this->shop() ? ($this->shop()->subdomain ?? '') . '.' . config('intake.domain', 'intake.works') . ' · Plan: ' . ucfirst((string) ($this->shop()->plan_tier ?? 'starter')) . ($this->shop()->owner ? ' · Owner ' . $this->shop()->owner->name : '') : 'Pick a shop to see its checklists.',
            'stuck' => 'Across every shop with the module on. Read-only. This is where to improve help articles or change the order of steps.',
            default => 'The steps a shop works through before a module goes live, and what Intake does when one stops being true.',
        };
    }

    public function show(string $view): void
    {
        $this->tab = $view;
    }

    public function setModule(string $module): void
    {
        if (! isset(LaunchDefaults::MODULES[$module])) return;
        if ($this->dirty && $this->tab === 'templates') {
            Notification::make()->title('Save or discard first')->body('You have unsaved changes to the ' . Launch::label($this->module) . ' checklist.')->warning()->send();
            return;
        }
        $this->module = $module;
        $this->loadTemplate();
    }

    // ------------------------------------------------------------ templates

    public function loadTemplate(): void
    {
        $this->behaviour = LaunchChecklist::behaviour($this->module);
        $this->steps = LaunchStep::where('module', $this->module)->orderBy('sort')->get()
            ->map(fn ($s) => $s->only(['id', 'key', 'check', 'group_name', 'title', 'explain', 'how', 'level', 'pause_on_fail', 'help_key', 'min_tier', 'enabled']))
            ->values()->all();
        $this->dirty = false;
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'behaviour') || str_starts_with($name, 'steps')) $this->dirty = true;
        // pause only makes sense on a required automatic step
        foreach ($this->steps as $i => $s) {
            if ($s['check'] === 'confirm' || $s['level'] !== 'req') $this->steps[$i]['pause_on_fail'] = false;
        }
    }

    public function move(int $i, int $d): void
    {
        $j = $i + $d;
        if (! isset($this->steps[$i], $this->steps[$j])) return;
        [$this->steps[$i], $this->steps[$j]] = [$this->steps[$j], $this->steps[$i]];
        $this->dirty = true;
    }

    public function discard(): void
    {
        $this->loadTemplate();
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate([
            'behaviour.remind_days' => ['required', 'integer', 'min:1', 'max:120'],
            'behaviour.grandfather' => ['required', 'in:live,checklist'],
            'steps.*.title'         => ['required', 'string', 'max:120'],
            'steps.*.level'         => ['required', 'in:req,rec,opt'],
            'steps.*.check'         => ['required', 'string', 'max:40'],
            'steps.*.min_tier'      => ['required', 'in:starter,branded,scale'],
        ]);
        $checks = array_keys(LaunchChecks::catalog());
        $b = $this->behaviour;
        LaunchChecklist::updateOrCreate(['module' => $this->module], ['settings' => [
            'hold_go_live' => (bool) $b['hold_go_live'], 'grandfather' => $b['grandfather'],
            'new_req_for_live' => (bool) $b['new_req_for_live'], 'email_owner' => (bool) $b['email_owner'],
            'add_issue' => (bool) $b['add_issue'], 'email_intake' => (bool) $b['email_intake'],
            'remind_days' => (int) $b['remind_days'], 'nudge' => (bool) $b['nudge'],
        ]]);
        $sort = 10;
        foreach ($this->steps as $s) {
            $check = in_array($s['check'], $checks, true) ? $s['check'] : 'confirm';
            $row = ! empty($s['id']) ? LaunchStep::find($s['id']) : null;
            $row ??= new LaunchStep(['module' => $this->module, 'key' => $s['key'] ?: Str::slug(Str::limit($s['title'], 30, ''), '_') . '_' . Str::lower(Str::random(4))]);
            $row->fill([
                'check' => $check, 'group_name' => trim((string) $s['group_name']) ?: 'Other', 'title' => trim($s['title']),
                'explain' => $s['explain'] ?: null, 'how' => $s['how'] ?: null, 'level' => $s['level'],
                'pause_on_fail' => $check !== 'confirm' && $s['level'] === 'req' && (bool) $s['pause_on_fail'],
                'help_key' => $s['help_key'] ?: null, 'min_tier' => $s['min_tier'], 'enabled' => (bool) $s['enabled'], 'sort' => $sort,
            ])->save();
            $sort += 10;
        }
        Launch::forgetMemo();
        $this->loadTemplate();
        Notification::make()->title('Saved')->body('The ' . Launch::label($this->module) . ' checklist applies to every shop straight away.')->success()->send();
    }

    public function openEdit(int $i): void
    {
        $this->editing = $i;
        $s = $this->steps[$i] ?? [];
        $this->edit = ['title' => $s['title'] ?? '', 'group_name' => $s['group_name'] ?? '', 'explain' => $s['explain'] ?? '', 'how' => $s['how'] ?? ''];
        $this->dispatch('open-modal', id: 'lc-edit');
    }

    public function useEdit(): void
    {
        if ($this->editing === null || ! isset($this->steps[$this->editing])) return;
        $this->validate(['edit.title' => ['required', 'string', 'max:120'], 'edit.group_name' => ['required', 'string', 'max:60'], 'edit.explain' => ['nullable', 'string', 'max:400'], 'edit.how' => ['nullable', 'string', 'max:600']]);
        foreach ($this->edit as $k => $v) $this->steps[$this->editing][$k] = $v;
        $this->dirty = true;
        $this->dispatch('close-modal', id: 'lc-edit');
    }

    public function openAdd(): void
    {
        $groups = array_values(array_unique(array_column($this->steps, 'group_name')));
        $this->add = ['title' => '', 'group_name' => end($groups) ?: 'Other', 'explain' => '', 'check' => 'confirm', 'level' => 'rec'];
        $this->dispatch('open-modal', id: 'lc-add');
    }

    public function addStep(): void
    {
        $this->validate(['add.title' => ['required', 'string', 'max:120'], 'add.group_name' => ['required', 'string', 'max:60'], 'add.level' => ['required', 'in:req,rec,opt']]);
        $this->steps[] = ['id' => null, 'key' => '', 'check' => $this->add['check'], 'group_name' => $this->add['group_name'], 'title' => $this->add['title'],
            'explain' => $this->add['explain'], 'how' => $this->add['check'] === 'confirm' ? 'The shop confirms this themselves.' : null, 'level' => $this->add['level'],
            'pause_on_fail' => false, 'help_key' => null, 'min_tier' => 'starter', 'enabled' => true];
        $this->dirty = true;
        $this->dispatch('close-modal', id: 'lc-add');
    }

    public function checkOptions(): array
    {
        $out = ['confirm' => 'You confirm (the shop ticks it)'];
        foreach (LaunchChecks::catalog() as $k => [$label, $mod]) {
            if ($mod === null || $mod === $this->module) $out[$k] = 'Auto: ' . $label;
        }
        return $out;
    }

    public function helpOptions(): array
    {
        try {
            return \App\Models\Tenant\TenantPage::query()->howTo()->whereNotNull('help_key')->orderBy('title')->pluck('title', 'help_key')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------ one shop

    public function shop(): ?Tenant
    {
        return $this->shopId ? Tenant::with('owner')->find($this->shopId) : null;
    }

    public function shopMatches(): array
    {
        $q = trim($this->shopSearch);
        return Tenant::query()->when($q !== '', fn ($w) => $w->where('name', 'like', '%' . $q . '%')->orWhere('subdomain', 'like', '%' . $q . '%'))
            ->orderBy('name')->limit(12)->get(['id', 'name', 'subdomain'])->all();
    }

    public function pickShop(string $id): void
    {
        $this->shopId = $id;
        $this->tab = 'shop';
        $mods = Launch::modules(Tenant::find($id));
        if (! isset($mods[$this->module])) $this->module = array_key_first($mods) ?? 'ecom';
    }

    public function shopData(): array
    {
        $t = $this->shop();
        if (! $t) return [];
        $out = [];
        foreach (Launch::modules($t) as $m => $label) {
            $out[$m] = Launch::evaluate($t, $m, $m === $this->module, $m === $this->module);
        }
        return $out;
    }

    public function history(): array
    {
        if (! $this->shopId) return [];
        return TenantLaunchEvent::where('tenant_id', $this->shopId)->where('module', $this->module)->orderByDesc('created_at')->limit(40)->get()->all();
    }

    private function who(): string
    {
        return (Auth::guard('web')->user()?->name ?: 'Intake') . ' (Intake)';
    }

    public function askNudgeFor(string $id): void
    {
        abort_unless($this->canEdit(), 403);
        $this->nudgeFor = $id;
        $this->dispatch('open-modal', id: 'lc-nudge');
    }

    public function ask(string $what, ?string $step = null): void
    {
        abort_unless($this->canEdit(), 403);
        $this->reason = '';
        $this->target = $step;
        $this->nudgeFor = null;
        $this->dispatch('open-modal', id: 'lc-' . $what);
    }

    public function doWaive(): void
    {
        abort_unless($this->canEdit() && $this->target, 403);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        Launch::mark($this->shop(), $this->module, $this->target, 'waived', $this->who(), $this->reason);
        $this->dispatch('close-modal', id: 'lc-waive');
        Notification::make()->title('Waived')->body('"' . Launch::stepTitle($this->module, $this->target) . '" counts as done for this shop.')->success()->send();
    }

    public function doClear(): void
    {
        abort_unless($this->canEdit() && $this->target, 403);
        Launch::mark($this->shop(), $this->module, $this->target, null, $this->who());
        $this->dispatch('close-modal', id: 'lc-clear');
        Notification::make()->title('Done')->body('"' . Launch::stepTitle($this->module, $this->target) . '" is back to its own state.')->success()->send();
    }

    public function doForce(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        Launch::forceLive($this->shop(), $this->module, $this->who(), $this->reason);
        $this->dispatch('close-modal', id: 'lc-force');
        Notification::make()->title('Allowed')->body('The shop can use Go live now. Required steps stay on its checklist.')->success()->send();
    }

    public function doPause(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        Launch::pauseByHand($this->shop(), $this->module, $this->who(), $this->reason);
        $this->dispatch('close-modal', id: 'lc-pause');
        Notification::make()->title('Paused')->body('The owner was emailed.')->warning()->send();
    }

    public function doLift(): void
    {
        abort_unless($this->canEdit(), 403);
        Launch::lift($this->shop(), $this->module, $this->who());
        $this->dispatch('close-modal', id: 'lc-lift');
        Notification::make()->title('Pause lifted')->success()->send();
    }

    public function doNudge(): void
    {
        abort_unless($this->canEdit(), 403);
        $t = $this->nudgeFor ? Tenant::find($this->nudgeFor) : $this->shop();
        $this->nudgeFor = null;
        if (! $t) return;
        $ev = Launch::evaluate($t, $this->module);
        if (! $ev['req_left']) {
            $this->dispatch('close-modal', id: 'lc-nudge');
            Notification::make()->title('Nothing to nudge')->body('Every required step is done.')->send();
            return;
        }
        Launch::sendNudge($t, $this->module, $ev, $this->who());
        $this->dispatch('close-modal', id: 'lc-nudge');
        Notification::make()->title('Nudge sent')->body('Emailed to ' . $t->name . '\'s owner and logged on the shop.')->success()->send();
    }

    public function rerun(): void
    {
        $t = $this->shop();
        if (! $t) return;
        LaunchChecks::forget($t);
        $n = 0;
        foreach (array_keys(Launch::modules($t)) as $m) { $n += Launch::evaluate($t, $m)['total']; }
        $this->dispatch('open-modal', id: 'lc-rerun');
        $this->target = (string) $n;
    }

    // ------------------------------------------------------------ where stuck

    public function stuck(): array
    {
        $m = $this->module;
        $states = TenantLaunchState::where('module', $m)->with('tenant:id,name,plan_tier')->get();
        $tenants = Tenant::query()->get();
        $on = 0;
        foreach ($tenants as $t) { if (isset(Launch::modules($t)[$m])) $on++; }
        $live = $states->whereIn('state', ['live', 'paused'])->count();
        $broken = $states->whereIn('state', ['live', 'paused'])->filter(fn ($s) => ! empty($s->broken) || $s->state === 'paused')->count();
        $preview = max(0, $on - $live);

        $steps = collect(Launch::steps($m))->where('level', 'req');
        $prevStates = $states->where('state', 'preview');
        $rows = [];
        foreach ($steps as $s) {
            $notDone = $prevStates->filter(fn ($st) => ! in_array(((array) $st->last)[$s->key] ?? null, ['ok', 'na', 'confirmed', 'skipped', 'waived'], true))->count();
            $waits = $states->reject(fn ($st) => $st->grandfathered)->map(function ($st) use ($s) {
                $p = ((array) $st->passed_at)[$s->key] ?? null;
                return ($p && $st->created_at) ? max(0, (int) $st->created_at->diffInDays(\Illuminate\Support\Carbon::parse($p))) : null;
            })->filter(fn ($v) => $v !== null)->sort()->values();
            $median = $waits->count() ? $waits[intdiv($waits->count(), 2)] : null;
            $rows[] = ['title' => $s->title, 'level' => $s->level, 'not_done' => $notDone, 'median' => $median];
        }
        usort($rows, fn ($a, $b) => $b['not_done'] <=> $a['not_done']);

        $days = (int) LaunchChecklist::behaviour($m)['remind_days'];
        $long = $prevStates->filter(fn ($st) => $st->created_at && (int) $st->created_at->diffInDays(now()) >= $days)
            ->map(function ($st) use ($m, $steps) {
                $left = $steps->filter(fn ($s) => ! in_array(((array) $st->last)[$s->key] ?? null, ['ok', 'na', 'confirmed', 'skipped', 'waived'], true))->pluck('title')->all();
                return ['id' => $st->tenant_id, 'name' => $st->tenant?->name ?? '?', 'days' => (int) $st->created_at->diffInDays(now()), 'left' => $left,
                    'reminded' => $st->reminders ? $st->reminders . ($st->reminders === 1 ? ' reminder' : ' reminders') . ($st->reminded_at ? ', last ' . $st->reminded_at->format('M j') : '') : 'Not yet'];
            })->sortByDesc('days')->values()->all();

        return ['on' => $on, 'live' => $live, 'preview' => $preview, 'broken' => $broken, 'rows' => $rows, 'long' => $long, 'days' => $days, 'prev_total' => $prevStates->count()];
    }
}
