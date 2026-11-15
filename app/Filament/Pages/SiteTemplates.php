<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\WebTemplate;
use App\Support\AdminAccess;
use App\Support\ShopSearch\SearchConfig;
use App\Support\SiteLook;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * MARKER-WEB-TEMPLATES: master admin › Site & content › Site templates.
 *
 *   list     every template, the shops using it, whether its colours read well, plans, status
 *   editor   Details · Colours (dark and light) · Pieces · Preview, with Save draft and Publish.
 *            Shops only ever see published values; publishing is blocked while any colour pair
 *            is hard to read.
 */
class SiteTemplates extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Site templates';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.site-templates';
    protected static ?string $slug            = 'site-templates';
    protected static ?string $title           = 'Site templates';

    #[Url(as: 't')] public ?string $editing = null;
    #[Url(as: 'tab')] public string $tab = 'details';

    public array $work = [];
    public string $saved = '';
    public array $new = ['name' => '', 'from' => ''];

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    public function getTitle(): string
    {
        return $this->editing ? ($this->work['name'] ?? 'Site template') : 'Site templates';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! in_array($this->tab, ['details', 'colours', 'pieces', 'preview'], true)) $this->tab = 'details';
        if ($this->editing) $this->load($this->editing);
    }

    // ------------------------------------------------------------------ list

    public function rows(): array
    {
        $using = Tenant::whereNotNull('site_template')->selectRaw('site_template, COUNT(*) AS n')->groupBy('site_template')->pluck('n', 'site_template');
        return WebTemplate::orderBy('sort')->orderBy('id')->get()->map(function ($t) use ($using) {
            $w = $t->working();
            return [
                'key' => $t->key, 'name' => $w['name'], 'description' => $w['description'], 'modes' => $w['modes'],
                'plans' => array_values(array_filter(array_keys(SearchConfig::TIERS), fn ($tier) => ! empty(((array) $w['plans'])[$tier]))),
                'shops' => (int) ($using[$t->key] ?? 0),
                'fails' => count(SiteLook::fails((array) $w['modes'])),
                'status' => $this->statusOf($t),
                'updated' => $t->updated_at,
            ];
        })->all();
    }

    private function statusOf(WebTemplate $t): string
    {
        if (! $t->isPublished()) return 'Draft';
        return $t->hasDraft() ? 'Published · unpublished changes' : 'Published';
    }

    public function openNew(): void
    {
        $this->new = ['name' => '', 'from' => WebTemplate::orderBy('sort')->value('key') ?? ''];
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: 'st-new');
    }

    public function create(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['new.name' => 'required|string|max:60', 'new.from' => 'required|string'], [], ['new.name' => 'name', 'new.from' => 'start from']);
        $src = WebTemplate::where('key', $this->new['from'])->first();
        if (! $src) { $this->addError('new.from', 'Pick a template to start from.'); return; }
        // tenants.site_template holds the key and is 24 characters long
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->new['name'])), '-'), 0, 20) ?: 'template';
        $key = $base; $i = 2;
        while (WebTemplate::where('key', $key)->exists()) $key = $base . '-' . $i++;
        $w = $src->working();
        WebTemplate::create([
            'key' => $key, 'name' => trim($this->new['name']), 'description' => '', 'status' => 'draft',
            'plans' => $w['plans'], 'modes' => $w['modes'], 'pieces' => $w['pieces'], 'sections' => $w['sections'],
            'sort' => (int) WebTemplate::max('sort') + 1,
        ]);
        $this->dispatch('close-modal', id: 'st-new');
        $this->edit($key);
        Notification::make()->title('Draft created from ' . $src->name)->body('Shops can\'t see it until you publish.')->success()->send();
    }

    // ------------------------------------------------------------------ editor

    public function edit(string $key): void
    {
        $this->editing = $key;
        $this->tab = 'details';
        $this->load($key);
    }

    private function load(string $key): void
    {
        $t = WebTemplate::where('key', $key)->first();
        if (! $t) { $this->editing = null; return; }
        $w = $t->working();
        $plans = [];
        foreach (SearchConfig::TIERS as $tier => $_) $plans[$tier] = (bool) (($w['plans'] ?? [])[$tier] ?? false);
        $modes = [];
        foreach (['dark', 'light'] as $m) foreach (SiteLook::ROLES as $r => $_) $modes[$m][$r] = strtolower((string) ($w['modes'][$m][$r] ?? '#000000'));
        $this->work = [
            'name' => (string) $w['name'], 'description' => (string) ($w['description'] ?? ''),
            'plans' => $plans, 'modes' => $modes,
            'pieces' => SiteLook::cleanPieces((array) $w['pieces']),
            'sections' => (array) ($w['sections'] ?? []),
        ];
        $this->saved = json_encode($this->work);
    }

    public function back(): void
    {
        $this->editing = null;
        $this->work = [];
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['details', 'colours', 'pieces', 'preview'], true) ? $tab : 'details';
    }

    public function isDirty(): bool
    {
        return $this->editing && json_encode($this->work) !== $this->saved;
    }

    public function template(): ?WebTemplate
    {
        return $this->editing ? WebTemplate::where('key', $this->editing)->first() : null;
    }

    public function fails(): array
    {
        return SiteLook::fails($this->work['modes'] ?? []);
    }

    public function shopsUsing(): array
    {
        return $this->editing ? Tenant::where('site_template', $this->editing)->orderBy('name')->limit(6)->pluck('name')->all() : [];
    }

    public function shopsUsingCount(): int
    {
        return $this->editing ? Tenant::where('site_template', $this->editing)->count() : 0;
    }

    /** Keep a typed hex colour and the colour picker in step. */
    public function updatedWork($value, $name): void
    {
        if (preg_match('/^modes\.(dark|light)\.(\w+)$/', (string) $name, $m)) {
            $h = SiteLook::hex(trim((string) $value));
            if ($h) $this->work['modes'][$m[1]][$m[2]] = $h;
        }
    }

    private function clean(): ?array
    {
        $w = $this->work;
        $name = trim((string) ($w['name'] ?? ''));
        if ($name === '') { Notification::make()->title('Give the template a name.')->danger()->send(); return null; }
        $modes = [];
        foreach (['dark', 'light'] as $m) {
            foreach (SiteLook::ROLES as $r => [$label]) {
                $h = SiteLook::hex($w['modes'][$m][$r] ?? null);
                if (! $h) { Notification::make()->title($label . ' (' . $m . ') needs a colour like #1a2b3c.')->danger()->send(); return null; }
                $modes[$m][$r] = $h;
            }
        }
        return [
            'name' => mb_substr($name, 0, 80), 'description' => mb_substr(trim((string) ($w['description'] ?? '')), 0, 400),
            'plans' => array_map(fn ($v) => (bool) $v, (array) ($w['plans'] ?? [])),
            'modes' => $modes, 'pieces' => SiteLook::cleanPieces((array) ($w['pieces'] ?? [])),
            'sections' => (array) ($w['sections'] ?? []),
        ];
    }

    public function saveDraft(): void
    {
        abort_unless($this->canEdit(), 403);
        $t = $this->template();
        if (! $t || ! ($d = $this->clean())) return;
        if ($t->isPublished()) {
            $t->update(['draft' => $d]);
        } else {
            $t->update($d + ['draft' => null]);
        }
        $this->load($t->key);
        Notification::make()->title('Draft saved')->body($t->isPublished() ? 'Shops still see the published version.' : 'Shops can\'t see it until you publish.')->success()->send();
    }

    public function askPublish(): void
    {
        if ($this->fails()) {
            Notification::make()->title('Can\'t publish yet')->body(count($this->fails()) . ' colour pair(s) are hard to read. Fix them on the Colours tab.')->danger()->send();
            return;
        }
        $this->dispatch('open-modal', id: 'st-publish');
    }

    public function publish(): void
    {
        abort_unless($this->canEdit(), 403);
        $t = $this->template();
        if (! $t || ! ($d = $this->clean())) return;
        if (SiteLook::fails($d['modes'])) { $this->askPublish(); return; }
        $t->update($d + ['draft' => null, 'status' => 'published', 'published_at' => $t->published_at ?? now()]);
        WebTemplate::flush($t->key);
        $this->load($t->key);
        $this->dispatch('close-modal', id: 'st-publish');
        Notification::make()->title($d['name'] . ' published')->body($this->shopsUsingCount() ? 'Shops using it see the change now, except pieces they changed themselves.' : 'Shops on the chosen plans can pick it now.')->success()->send();
    }

    public function discard(): void
    {
        if ($this->editing) $this->load($this->editing);
    }

    public function discardDraft(): void
    {
        abort_unless($this->canEdit(), 403);
        $t = $this->template();
        if ($t && $t->isPublished()) { $t->update(['draft' => null]); $this->load($t->key); }
        Notification::make()->title('Unpublished changes thrown away')->success()->send();
    }
}
