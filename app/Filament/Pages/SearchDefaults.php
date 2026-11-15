<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use App\Models\SearchDefault;
use App\Support\AdminAccess;
use App\Support\ShopSearch\SearchConfig;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * MARKER-SHOP-SEARCH-V2: master admin › Search defaults.
 *
 *   Default synonyms   what every shop's search starts with (explicit Save)
 *   Stop words         words ignored when they are what stops a search matching (explicit Save)
 *   Plans              which plan gets which search feature (explicit Save)
 *   How shops use it   read-only: searches, no-results rate, the commonest no-results searches across shops
 */
class SearchDefaults extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationLabel = 'Search defaults';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 21;
    protected static string  $view            = 'filament.pages.search-defaults';
    protected static ?string $slug            = 'search-defaults';
    protected static ?string $title           = 'Search defaults';

    #[Url(as: 'view')] public string $tab = 'synonyms';

    public array $syn = [];     // [id|null, from, to, enabled]
    public array $stop = [];    // words
    public array $plans = [];   // feature => tier => bool
    public bool $dirtySyn = false;
    public bool $dirtyStop = false;
    public bool $dirtyPlans = false;

    public array $add = ['from' => '', 'to' => ''];
    public string $newStop = '';
    public ?int $removing = null;

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
        if (! in_array($this->tab, ['synonyms', 'stop', 'plans', 'usage'], true)) $this->tab = 'synonyms';
        $this->load();
    }

    private function load(): void
    {
        $this->syn = SearchDefault::where('type', 'synonym')->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($d) => ['id' => $d->id, 'from' => $d->from_term, 'to' => (string) $d->to_value, 'on' => (bool) $d->enabled])->all();
        $this->stop = SearchDefault::where('type', 'stop')->where('enabled', true)->orderBy('sort')->orderBy('id')->pluck('from_term')->all();
        SearchConfig::forget();
        $this->plans = SearchConfig::plans();
        $this->dirtySyn = $this->dirtyStop = $this->dirtyPlans = false;
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['synonyms', 'stop', 'plans', 'usage'], true) ? $tab : 'synonyms';
    }

    public function updated($name): void
    {
        if (str_starts_with($name, 'syn')) $this->dirtySyn = true;
        if (str_starts_with($name, 'plans')) $this->dirtyPlans = true;
    }

    // ---------------------------------------------------------------- synonyms

    public function openAdd(string $from = ''): void
    {
        $this->add = ['from' => $from, 'to' => ''];
        $this->tab = 'synonyms'; // the new row lands in the list, ready to save
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: 'sd-add');
    }

    public function addSyn(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['add.from' => 'required|string|max:120', 'add.to' => 'required|string|max:120|different:add.from'], [], ['add.from' => 'shopper types', 'add.to' => 'treated as']);
        $from = mb_strtolower(trim($this->add['from']));
        if (collect($this->syn)->contains(fn ($s) => mb_strtolower($s['from']) === $from)) {
            $this->addError('add.from', '"' . $from . '" already has a default. Edit that one.');
            return;
        }
        $this->syn[] = ['id' => null, 'from' => $from, 'to' => mb_strtolower(trim($this->add['to'])), 'on' => true];
        $this->dirtySyn = true;
        $this->dispatch('close-modal', id: 'sd-add');
    }

    public function askRemove(int $i): void
    {
        $this->removing = $i;
        $this->dispatch('open-modal', id: 'sd-del');
    }

    public function removeSyn(): void
    {
        abort_unless($this->canEdit(), 403);
        if ($this->removing !== null && isset($this->syn[$this->removing])) {
            array_splice($this->syn, $this->removing, 1);
            $this->dirtySyn = true;
        }
        $this->removing = null;
        $this->dispatch('close-modal', id: 'sd-del');
    }

    public function saveSyn(): void
    {
        abort_unless($this->canEdit(), 403);
        foreach ($this->syn as $i => $s) {
            if (trim($s['from']) === '' || trim($s['to']) === '') {
                Notification::make()->title('Row ' . ($i + 1) . ' needs both words.')->danger()->send();
                return;
            }
        }
        DB::transaction(function () {
            $keep = [];
            foreach (array_values($this->syn) as $i => $s) {
                $attrs = ['type' => 'synonym', 'from_term' => mb_strtolower(trim($s['from'])), 'to_value' => mb_strtolower(trim($s['to'])), 'enabled' => (bool) $s['on'], 'sort' => $i];
                $row = $s['id'] ? SearchDefault::find($s['id']) : null;
                $row ? $row->update($attrs) : ($row = SearchDefault::create($attrs));
                $keep[] = $row->id;
            }
            $gone = SearchDefault::where('type', 'synonym')->whereNotIn('id', $keep)->pluck('id');
            DB::table('tenant_search_default_offs')->whereIn('default_id', $gone)->delete();
            SearchDefault::whereIn('id', $gone)->delete();
        });
        $this->flush();
        $this->load();
        Notification::make()->title('Default synonyms saved. Every shop has them within a minute.')->success()->send();
    }

    // ---------------------------------------------------------------- stop words

    public function addStop(): void
    {
        abort_unless($this->canEdit(), 403);
        foreach (preg_split('/[,\s]+/', mb_strtolower($this->newStop)) as $w) {
            if ($w !== '' && ! in_array($w, $this->stop, true)) $this->stop[] = $w;
        }
        $this->newStop = '';
        $this->dirtyStop = true;
    }

    public function removeStop(int $i): void
    {
        abort_unless($this->canEdit(), 403);
        array_splice($this->stop, $i, 1);
        $this->dirtyStop = true;
    }

    public function saveStop(): void
    {
        abort_unless($this->canEdit(), 403);
        DB::transaction(function () {
            $old = SearchDefault::where('type', 'stop')->pluck('id', 'from_term');
            foreach (array_values($this->stop) as $i => $w) {
                SearchDefault::updateOrCreate(['type' => 'stop', 'from_term' => $w], ['enabled' => true, 'sort' => 1000 + $i]);
            }
            $gone = $old->except($this->stop)->values();
            DB::table('tenant_search_default_offs')->whereIn('default_id', $gone)->delete();
            SearchDefault::whereIn('id', $gone)->delete();
        });
        $this->flush();
        $this->load();
        Notification::make()->title('Stop words saved.')->success()->send();
    }

    // ---------------------------------------------------------------- plans

    public function savePlans(): void
    {
        abort_unless($this->canEdit(), 403);
        $clean = [];
        foreach (array_keys(SearchConfig::FEATURES) as $k) {
            foreach (array_keys(SearchConfig::TIERS) as $t) $clean[$k][$t] = (bool) ($this->plans[$k][$t] ?? false);
        }
        PlatformSettings::forget();
        $ps = PlatformSettings::current();
        $cur = (array) ($ps->search_settings ?? []);
        $cur['plans'] = $clean;
        $ps->search_settings = $cur;
        $ps->save();
        PlatformSettings::forget();
        $this->load();
        Notification::make()->title('Search plans saved.')->body('Shops that lose a feature keep what they already made; they cannot add new ones.')->success()->send();
    }

    public function discard(string $what): void
    {
        $this->load();
    }

    private function flush(): void
    {
        // every shop's vocabulary is cached for a minute; it refreshes by itself
        SearchConfig::forget();
    }

    // ---------------------------------------------------------------- usage

    public function usage(): array
    {
        return Cache::remember('search-defaults-usage', 600, function () {
            $from = now()->subDays(30);
            $q = DB::table('tenant_search_queries')->where('created_at', '>=', $from);
            $n = (clone $q)->count();
            $zero = (clone $q)->where('results_count', 0)->count();
            $common = (clone $q)->where('results_count', 0)
                ->selectRaw('LOWER(query) AS k, COUNT(DISTINCT tenant_id) AS shops, COUNT(*) AS n')
                ->groupByRaw('LOWER(query)')->orderByDesc('shops')->orderByDesc('n')->limit(20)->get();
            return [
                'shops'   => (clone $q)->distinct()->count('tenant_id'),
                'n'       => $n,
                'zero'    => $n ? round($zero / $n * 100, 1) : 0,
                'rules'   => DB::table('tenant_search_rules')->distinct()->count('tenant_id'),
                'promos'  => DB::table('tenant_search_promotions')->distinct()->count('tenant_id'),
                'common'  => $common->map(fn ($r) => ['q' => $r->k, 'shops' => (int) $r->shops, 'n' => (int) $r->n])->all(),
            ];
        });
    }
}
