<?php
// MARKER-SALES-BOARD

namespace App\Filament\Pages;

use App\Models\SalesProspect;
use App\Models\SalesRep;
use App\Models\SalesTerritory;
use App\Services\Sales\PlacesClient;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline — every open prospect as a card in its stage column. Drag to move;
 * click to open the drawer. Same rows and stages as the Prospects list.
 */
class SalesPipeline extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-flag';
    protected static ?string $navigationLabel = 'Prospects'; // MARKER-SALES-PROSPECTS2 — Pipeline + the Prospects list, one page
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 8;
    protected static string  $view            = 'filament.pages.sales-pipeline';
    protected static ?string $slug            = 'sales-pipeline';
    protected static ?string $title           = 'Prospects';

    public const PER_COLUMN = 80;

    // filters
    public string $territoryId = '';
    public string $repId       = '';
    public string $priority    = '';
    public bool   $dueOnly     = false;
    public bool   $hideUntouched = false; // MARKER-PROSPECTS-SORT — off by default: imported shops show
    public string $sortBy  = '';         // MARKER-PROSPECTS-SORT — List view column sort ('' = due first, then score)
    public string $sortDir = 'asc';
    public array  $states  = [];         // MARKER-PROSPECTS-PLACE — any of these states
    public string $zip     = '';
    public array  $brandsSel = [];       // MARKER-PROSPECTS-BRANDS — shops carrying any of these         // MARKER-PROSPECTS-PLACE — ZIPs or ZIP starts, comma separated
    public string $site        = ''; // MARKER-SALES-SITE-FILTER — what the website pass found
    public bool   $showClosed  = false;
    public string $q           = '';

    // drawer
    public ?string $openId = null;
    public string  $tab    = 'profile';
    public string  $logType = 'call';
    public string  $logBody = '';
    public ?string $logNext = null;
    public string  $logNextAction = '';
    public string  $notes = '';
    public string  $lostReason = '';
    public ?string $quoteTier = null;
    public array   $quoteAddons = [];

    // MARKER-SALES-INVITE
    public bool   $showInvite  = false;
    public string $inviteEmail = '';
    public string $inviteName  = '';
    public string $invitePlan  = 'scale';
    public string $inviteMessage = '';
    public string $linkTenantId = '';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->mode       = in_array(session('sales.view'), ['board', 'list'], true) ? session('sales.view') : 'board';
        $this->industryId = (string) session('sales.industry', '');
        $this->sortBy  = (string) session('sales.sort', '');           // MARKER-PROSPECTS-SORT
        $this->sortDir = session('sales.sort_dir') === 'desc' ? 'desc' : 'asc';
        if ($this->industryId !== '' && ! \App\Models\SalesChannel::whereKey($this->industryId)->exists()) $this->industryId = '';
        if ($id = request()->query('open')) $this->open($id);
    }

    // ---------------------------------------------------------------- data
    public function stages(): array
    {
        $s = SalesProspect::STAGES;
        if (! $this->showClosed) unset($s['won'], $s['lost']);
        return $s;
    }

    protected function baseQuery()
    {
        return SalesProspect::query()->with(['rep', 'territory'])
            ->when($this->industryId, fn ($q) => $q->where('channel_id', $this->industryId))
            ->when($this->territoryId === 'none', fn ($q) => $q->whereNull('territory_id'))
            ->when($this->territoryId && $this->territoryId !== 'none', fn ($q) => $q->where('territory_id', $this->territoryId))
            ->when($this->repId === 'none', fn ($q) => $q->whereNull('sales_rep_id'))
            ->when($this->repId && $this->repId !== 'none', fn ($q) => $q->where('sales_rep_id', $this->repId))
            ->when($this->priority, fn ($q) => $q->where('priority', $this->priority))
            ->when($this->dueOnly, fn ($q) => $q->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))
            // MARKER-SALES-SITE-FILTER — a Website pass filter shows untouched shops too; that's the point of it
            ->when($this->site === 'found', fn ($q) => $q->where(fn ($w) => $w->where(fn ($e) => $e->whereNotNull('email')->where('email', '!=', ''))->orWhereNotNull('socials')->orWhereNotNull('brands')))
            ->when($this->site === 'email', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->when($this->site === 'phone', fn ($q) => $q->whereNotNull('phone')->where('phone', '!=', ''))
            ->when($this->site === 'instagram', fn ($q) => $q->whereNotNull('socials->instagram'))
            ->when($this->site === 'facebook', fn ($q) => $q->whereNotNull('socials->facebook'))
            ->when($this->site === 'owner', fn ($q) => $q->whereNotNull('owner_contact')->where('owner_contact', '!=', ''))
            ->when($this->site === 'brands', fn ($q) => $q->whereNotNull('brands'))
            ->when($this->site === 'nothing', fn ($q) => $q->whereIn('site_scan_status', ['nothing_found', 'unreachable', 'not_shop_site', 'name_mismatch']))
            ->when($this->site === 'unread', fn ($q) => $q->whereNull('site_scanned_at')->whereNotNull('website')->where('website', '!=', ''))
            // MARKER-PROSPECTS-PLACE
            ->when($this->brandsSel, fn ($q) => $q->where(function ($w) {  // MARKER-PROSPECTS-BRANDS
                foreach (array_slice(array_values(array_filter(array_map('strval', $this->brandsSel))), 0, 40) as $b) $w->orWhereJsonContains('brands', $b);
            }))
            ->when($this->states, fn ($q) => $q->whereIn('state', array_values(array_filter(array_map(fn ($s) => strtoupper(substr((string) $s, 0, 2)), $this->states)))))
            ->when($this->zipTokens(), fn ($q) => $q->where(function ($w) {
                foreach ($this->zipTokens() as $z) $w->orWhere('postcode', 'like', $z . '%');
            }))
            ->when($this->hideUntouched && $this->site === '', fn ($q) => $q->where(fn ($w) => $w->where('stage', '!=', 'prospect')->orWhereNotNull('last_contacted_at')->orWhereNotNull('next_action_on')->orWhereNotNull('sales_rep_id')))
            ->when(trim($this->q) !== '', fn ($q) => $q->where(fn ($w) => $w->where('shop', 'like', '%' . trim($this->q) . '%')->orWhere('city', 'like', '%' . trim($this->q) . '%')));
    }

    /** @return array<string, array{rows: \Illuminate\Support\Collection, total:int}> */
    public function columns(): array
    {
        $out = [];
        $counts = (clone $this->baseQuery())->select('stage', DB::raw('count(*) c'))->groupBy('stage')->pluck('c', 'stage');
        foreach ($this->stages() as $key => $label) {
            $rows = (clone $this->baseQuery())->where('stage', $key)
                ->orderByRaw('CASE WHEN next_action_on IS NOT NULL AND next_action_on <= CURDATE() THEN 0 ELSE 1 END')
                ->orderByDesc('lead_score')->orderBy('shop')->limit(self::PER_COLUMN)->get();
            $out[$key] = ['rows' => $rows, 'total' => (int) ($counts[$key] ?? 0)];
        }
        return $out;
    }

    public function funnel(): array
    {
        $c = SalesProspect::query()->select('stage', DB::raw('count(*) c'))->groupBy('stage')->pluck('c', 'stage');
        $won = SalesProspect::query()->where('stage', 'won');
        return [
            'counts'  => $c,
            'wonMrr'  => (int) (clone $won)->sum('quote_monthly'),
            'tenants' => (int) (clone $won)->whereNotNull('tenant_id')->count(),
            'due'     => (int) SalesProspect::query()->open()->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now())->count(),
        ];
    }

    public function territories() { return SalesTerritory::query()->orderBy('priority')->get(['id', 'name']); }
    public function reps() { return SalesRep::query()->with('agency')->where('status', 'active')->orderBy('name')->get(); }
    public function addons() { return DB::table('addons')->where('price_cents', '>', 0)->orderBy('name')->get(['code', 'name', 'price_cents']); }
    public function tiers(): array
    {
        return collect(\App\Support\PlanPricing::all())->filter(fn ($c) => (int) $c > 0)->all();
    }

    public function current(): ?SalesProspect
    {
        return $this->openId ? SalesProspect::with(['rep.agency', 'territory', 'tenant', 'activities', 'channel'])->find($this->openId) : null;
    }

    // ---------------------------------------------------------------- board actions
    public function moveStage(string $id, string $stage): void
    {
        if (! isset(SalesProspect::STAGES[$stage])) return;
        $p = SalesProspect::find($id);
        if (! $p || $p->stage === $stage) return;
        if ($stage === 'lost') { $this->open($id); $this->tab = 'profile'; $this->lostReason = ''; $this->dispatch('board-lost-prompt'); return; }
        $p->advanceTo($stage, 'Moved on the pipeline board by ' . (Auth::user()?->name ?? 'staff'));
        Notification::make()->title($p->shop . ' → ' . SalesProspect::STAGES[$stage])->success()->send();
    }

    // ---------------------------------------------------------------- drawer
    public function open(string $id): void
    {
        $p = SalesProspect::find($id);
        if (! $p) return;
        $this->openId = $id; $this->tab = 'profile';
        $this->notes = (string) $p->notes; $this->lostReason = (string) $p->lost_reason;
        $this->quoteTier = $p->quote_tier; $this->quoteAddons = array_values((array) $p->quote_addons);
        $this->logBody = ''; $this->logNext = null; $this->logNextAction = '';
        $this->contactName = (string) $p->owner_contact; $this->contactEmail = (string) $p->email;
    }

    public function close(): void { $this->openId = null; $this->showInvite = false; $this->showEmail = false; }

    // ---------------------------------------------------------------- MARKER-SALES-INVITE
    public function openInvite(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->showInvite  = true;
        $this->inviteEmail = $p->invite_email ?: (string) $p->email;
        $this->inviteName  = (string) $p->owner_contact;
        $this->invitePlan  = $p->invite_plan ?: ($p->quote_tier ?: 'scale');
        $this->inviteMessage = '';
    }

    public function sendInvite(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:191'],
            'inviteName'  => ['nullable', 'string', 'max:191'],
            'invitePlan'  => ['required', 'in:' . implode(',', array_keys($this->tiers()))],
            'inviteMessage' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $u = Auth::user();
            \App\Services\Sales\ProspectConversion::invite($p, strtolower(trim($this->inviteEmail)), $this->invitePlan, trim($this->inviteName) ?: null, trim($this->inviteMessage) ?: null, $u?->name, $u?->email);
            $this->showInvite = false;
            Notification::make()->title('Invite sent to ' . $this->inviteEmail)->body('The card moves to Trial when they sign up, and to Won on the first paid invoice.')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Invite failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function linkTenant(): void
    {
        $p = $this->current(); if (! $p || ! $this->linkTenantId) return;
        $t = \App\Models\Tenant::find($this->linkTenantId); if (! $t) return;
        if (SalesProspect::where('tenant_id', $t->id)->where('id', '!=', $p->id)->exists()) {
            Notification::make()->title('That tenant is already linked to another prospect')->warning()->send(); return;
        }
        \App\Services\Sales\ProspectConversion::link($p, $t, 'Linked by ' . (Auth::user()?->name ?? 'staff'));
        $this->linkTenantId = '';
        Notification::make()->title('Linked to ' . $t->name)->success()->send();
    }

    public function linkableTenants()
    {
        $taken = SalesProspect::query()->whereNotNull('tenant_id')->pluck('tenant_id');
        return \App\Models\Tenant::query()->where('is_platform', false)->whereNotIn('id', $taken)->orderBy('name')->get(['id', 'name', 'subdomain']);
    }
    public function setTab(string $t): void { $this->tab = $t; }

    public function setStage(string $stage): void
    {
        $p = $this->current(); if (! $p || ! isset(SalesProspect::STAGES[$stage])) return;
        if ($stage === 'lost' && trim($this->lostReason) === '') {
            Notification::make()->title('Say why it was lost')->warning()->send(); return;
        }
        if ($stage === 'lost') $p->update(['lost_reason' => trim($this->lostReason)]);
        $p->advanceTo($stage, $stage === 'lost' ? trim($this->lostReason) : null);
        Notification::make()->title('Stage: ' . SalesProspect::STAGES[$stage])->success()->send();
    }

    public function setPriority(string $pr): void
    {
        $p = $this->current(); if (! $p || ! isset(SalesProspect::PRIORITIES[$pr])) return;
        $p->update(['priority' => $pr]);
    }

    public function setRep(string $repId): void
    {
        $p = $this->current(); if (! $p) return;
        if ($repId === '') { $p->update(['sales_rep_id' => null]); $p->activities()->create(['type' => 'system', 'body' => 'Rep cleared']); return; }
        $rep = SalesRep::find($repId); if (! $rep) return;
        $p->update(['sales_rep_id' => $rep->id, 'agency_id' => $rep->agency_id]);
        $p->activities()->create(['type' => 'system', 'body' => 'Assigned to ' . $rep->name]);
    }

    public function saveLog(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->validate(['logBody' => ['required', 'string', 'max:2000'], 'logNext' => ['nullable', 'date'], 'logNextAction' => ['nullable', 'string', 'max:191']]);
        $p->activities()->create(['type' => $this->logType, 'body' => trim($this->logBody)]);
        $changes = ['last_contacted_at' => now()];
        if ($this->logNext) { $changes['next_action_on'] = $this->logNext; $changes['next_action'] = trim($this->logNextAction) ?: $p->next_action; }
        if ($p->stage === 'prospect' && in_array($this->logType, ['call', 'email', 'demo'], true)) $changes['stage'] = 'contacted';
        $p->update($changes);
        $this->logBody = ''; $this->logNext = null; $this->logNextAction = '';
        Notification::make()->title('Logged')->success()->send();
    }

    public function clearNext(): void
    {
        $p = $this->current(); if (! $p) return;
        $p->update(['next_action_on' => null, 'next_action' => null]);
    }

    public function saveNotes(): void
    {
        $p = $this->current(); if (! $p) return;
        $p->update(['notes' => trim($this->notes) ?: null]);
        Notification::make()->title('Notes saved')->success()->send();
    }

    public function saveQuote(): void
    {
        $p = $this->current(); if (! $p) return;
        $p->update(['quote_tier' => $this->quoteTier ?: null, 'quote_addons' => array_values($this->quoteAddons)]);
        $p->refresh();
        $p->activities()->create(['type' => 'note', 'body' => 'Quote saved · $' . number_format((int) $p->quote_monthly) . '/mo']);
        Notification::make()->title('Quote saved · $' . number_format((int) $p->quote_monthly) . '/mo')->success()->send();
    }

    public function quotePreview(): int
    {
        $p = $this->current(); if (! $p) return 0;
        $tmp = clone $p; $tmp->quote_tier = $this->quoteTier ?: null; $tmp->quote_addons = array_values($this->quoteAddons);
        return (int) ($tmp->computeQuoteMonthly() ?? 0);
    }

    /** MARKER-SALES-ROUTE — shared with the bulk action and Route day. */
    public function enrich(): void
    {
        $p = $this->current(); if (! $p) return;
        try {
            $r = \App\Services\Sales\ProspectEnricher::enrich($p);
            Notification::make()->title($r === 'no record' ? 'Places has no record for this shop' : 'Details pulled')->body($r)->{$r === 'no record' ? 'warning' : 'success'}()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Places failed')->body($e->getMessage())->danger()->send();
        }
    }

    // ---------------------------------------------------------------- MARKER-SALES-PROSPECTS2
    // Pipeline and the Prospects list are one page now: Board or List, one set
    // of filters, scoped to an industry. The old list (SalesProspectResource)
    // stays only for its full-record edit and create pages.
    public string $mode       = 'board';
    public string $industryId = '';
    public array  $selected   = [];
    public int    $listPage   = 1;
    public string $bulkAction = '';
    public string $bulkValue  = '';
    public bool   $confirmPull = false;
    public string $contactName  = '';
    public string $contactEmail = '';

    public const LIST_PER_PAGE = 50;
    public const PULL_LIMIT    = 100;

    public function updatedMode(): void       { session(['sales.view' => $this->mode]); $this->selected = []; }
    public function updatedIndustryId(): void { session(['sales.industry' => $this->industryId]); $this->listPage = 1; $this->selected = []; }
    public function updated($name): void
    {
        if (in_array(explode('.', $name)[0], ['q', 'territoryId', 'repId', 'priority', 'dueOnly', 'hideUntouched', 'showClosed', 'site', 'states', 'zip', 'brandsSel'], true)) {
            $this->listPage = 1; $this->selected = [];
        }
    }

    /** Industries with how many prospects each has, for the switcher. */
    public function industries()
    {
        return \App\Models\SalesChannel::query()->where('status', '!=', 'stub')
            ->withCount('prospects')->orderByDesc('prospects_count')->orderBy('name')->get(['id', 'name', 'best_ask', 'playbook', 'status']);
    }

    public function stats(): array
    {
        $base = SalesProspect::query()->when($this->industryId, fn ($q) => $q->where('channel_id', $this->industryId));
        $total = (clone $base)->count();
        $plans = \App\Support\PlanPricing::all();
        $floor = $plans ? min(array_filter($plans)) / 100 : 89;
        return [
            'total'    => $total,
            'a'        => (clone $base)->where('priority', 'A')->count(),
            'verified' => (clone $base)->where('verified', true)->count(),
            'trials'   => (clone $base)->where('stage', 'trial')->count(),
            'won'      => (clone $base)->where('stage', 'won')->count(),
            'tenants'  => (clone $base)->whereNotNull('tenant_id')->count(),
            'due'      => (clone $base)->open()->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now())->count(),
            'value'    => (int) round((clone $base)->whereIn('priority', ['A', 'B'])->get(['lead_score'])->sum(fn ($p) => ($p->lead_score / 110) * $floor)),
        ];
    }

    /** How many prospects "Hide untouched" is hiding right now. */
    public function hiddenCount(): int
    {
        if (! $this->hideUntouched || $this->site !== '') return 0; // MARKER-SALES-SITE-FILTER
        $was = $this->hideUntouched;
        $this->hideUntouched = false;
        $all = (clone $this->baseQuery())->when(! $this->showClosed, fn ($q) => $q->open())->count();
        $this->hideUntouched = $was;
        $shown = (clone $this->baseQuery())->when(! $this->showClosed, fn ($q) => $q->open())->count();
        return max(0, $all - $shown);
    }

    public function listRows(): array
    {
        $q = (clone $this->baseQuery())->with(['channel'])->when(! $this->showClosed, fn ($w) => $w->open());
        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / self::LIST_PER_PAGE));
        $this->listPage = min(max(1, $this->listPage), $pages);
        // MARKER-PROSPECTS-SORT — a clicked column wins; blanks always sort last
        $d = $this->sortDir === 'desc' ? 'desc' : 'asc';
        $blankLast = fn (string $col) => $q->orderByRaw("CASE WHEN $col IS NULL OR $col = '' THEN 1 ELSE 0 END");
        switch ($this->sortBy) {
            case 'shop':     $q->orderBy('shop', $d); break;
            case 'place':    $blankLast('state'); $q->orderBy('state', $d)->orderBy('city', $d)->orderBy('postcode', $d); break;
            case 'contact':  $q->orderByRaw("(CASE WHEN email IS NULL OR email = '' THEN 0 ELSE 2 END) + (CASE WHEN phone IS NULL OR phone = '' THEN 0 ELSE 1 END) " . ($d === 'asc' ? 'DESC' : 'ASC')); break;
            case 'industry': $q->orderByRaw('CASE WHEN channel_id IS NULL THEN 1 ELSE 0 END')
                                ->orderByRaw('(SELECT name FROM sales_channels WHERE sales_channels.id = sales_prospects.channel_id) ' . $d); break;
            case 'loop':     $q->orderByRaw('CASE WHEN `loop` IS NULL THEN 1 ELSE 0 END')->orderBy('loop', $d); break; // loop is a MySQL keyword
            case 'priority': $q->orderBy('priority', $d); break;
            case 'verified': $q->orderBy('verified', $d === 'asc' ? 'desc' : 'asc'); break;
            case 'score':    $q->orderBy('lead_score', $d === 'asc' ? 'desc' : 'asc'); break;
            case 'rep':      $q->orderByRaw('CASE WHEN sales_rep_id IS NULL THEN 1 ELSE 0 END')
                                ->orderByRaw('(SELECT name FROM sales_reps WHERE sales_reps.id = sales_prospects.sales_rep_id) ' . $d); break;
            case 'stage':    $q->orderByRaw("FIELD(stage, '" . implode("','", array_map(fn ($s) => str_replace("'", '', $s), array_keys(SalesProspect::STAGES))) . "') " . strtoupper($d)); break;
            case 'next':     $q->orderByRaw('CASE WHEN next_action_on IS NULL THEN 1 ELSE 0 END')->orderBy('next_action_on', $d); break;
            case 'quote':    $q->orderByRaw('CASE WHEN quote_monthly IS NULL THEN 1 ELSE 0 END')->orderBy('quote_monthly', $d === 'asc' ? 'desc' : 'asc'); break;
            default:         $q->orderByRaw('CASE WHEN next_action_on IS NOT NULL AND next_action_on <= CURDATE() THEN 0 ELSE 1 END')->orderByDesc('lead_score');
        }
        $rows = $q->orderBy('shop')->forPage($this->listPage, self::LIST_PER_PAGE)->get();
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
    }

    /** MARKER-PROSPECTS-PLACE — "992, 83814" → ['992', '83814']; digits only, 2 to 5 of them. */
    public function zipTokens(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($t) => preg_match('/^\d{2,5}$/', $t = preg_replace('/\D/', '', $t)) ? $t : null,
            preg_split('/[\s,;]+/', $this->zip)
        ))));
    }

    /** States that have prospects, with counts, for the state picker. */
    public function stateCounts(): array
    {
        return SalesProspect::query()->whereNotNull('state')->where('state', '!=', '')
            ->select('state', DB::raw('COUNT(*) n'))->groupBy('state')->orderBy('state')->pluck('n', 'state')->all();
    }

    /** MARKER-PROSPECTS-BRANDS — brands the website pass found, most common first, for the picker. Cached 10 minutes. */
    public function brandCounts(): array
    {
        $key = 'sales:brand-counts:' . ($this->industryId ?: 'all');
        return \Illuminate\Support\Facades\Cache::remember($key, 600, function () {
            $n = [];
            SalesProspect::query()->whereNotNull('brands')
                ->when($this->industryId, fn ($q) => $q->where('channel_id', $this->industryId))
                ->select(['id', 'brands'])->chunkById(2000, function ($rows) use (&$n) {
                    foreach ($rows as $r) foreach ((array) $r->brands as $b) if (is_string($b) && $b !== '') $n[$b] = ($n[$b] ?? 0) + 1;
                });
            arsort($n);
            return $n;
        });
    }

    public function clearBrands(): void { $this->brandsSel = []; $this->listPage = 1; $this->selected = []; }

    public function clearStates(): void { $this->states = []; $this->listPage = 1; $this->selected = []; }

    /** MARKER-PROSPECTS-SORT — click a column: sort by it; click again: reverse; a third time: back to the default order. */
    public function sortList(string $key): void
    {
        $keys = ['shop', 'place', 'contact', 'industry', 'loop', 'priority', 'verified', 'score', 'rep', 'stage', 'next', 'quote'];
        if (! in_array($key, $keys, true)) return;
        if ($this->sortBy !== $key)          { $this->sortBy = $key; $this->sortDir = 'asc'; }
        elseif ($this->sortDir === 'asc')    { $this->sortDir = 'desc'; }
        else                                 { $this->sortBy = ''; $this->sortDir = 'asc'; }
        session(['sales.sort' => $this->sortBy, 'sales.sort_dir' => $this->sortDir]);
        $this->listPage = 1;
    }

    public function toggleAllOnPage(array $ids): void
    {
        $allIn = ! array_diff($ids, $this->selected);
        $this->selected = $allIn ? array_values(array_diff($this->selected, $ids)) : array_values(array_unique(array_merge($this->selected, $ids)));
    }

    public function applyBulk(): void
    {
        $ids = array_values(array_unique($this->selected));
        if (! $ids || $this->bulkAction === '') return;
        $by = Auth::user()?->name ?? 'staff';
        $rows = SalesProspect::query()->whereIn('id', $ids)->get();
        $n = 0;

        switch ($this->bulkAction) {
            case 'stage':
                if (! isset(SalesProspect::STAGES[$this->bulkValue]) || $this->bulkValue === 'lost') {
                    Notification::make()->title('Pick a stage. To mark a shop lost, open it and give the reason.')->warning()->send(); return;
                }
                foreach ($rows as $p) if ($p->stage !== $this->bulkValue) { $p->advanceTo($this->bulkValue, "Set in bulk by $by"); $n++; }
                break;
            case 'rep':
                $rep = $this->bulkValue ? SalesRep::find($this->bulkValue) : null;
                foreach ($rows as $p) {
                    $p->update(['sales_rep_id' => $rep?->id, 'agency_id' => $rep?->agency_id]);
                    $p->activities()->create(['type' => 'system', 'body' => $rep ? "Assigned to {$rep->name} by $by" : "Rep cleared by $by"]);
                    $n++;
                }
                break;
            case 'industry':
                $c = $this->bulkValue ? \App\Models\SalesChannel::find($this->bulkValue) : null;
                foreach ($rows as $p) { $p->update(['channel_id' => $c?->id]); $n++; }
                break;
            case 'territory':
                \App\Services\Sales\TerritoryResolver::forget();
                foreach ($rows as $p) if (\App\Services\Sales\TerritoryResolver::apply($p)) $n++;
                break;
            case 'verify':
                foreach ($rows as $p) if (! $p->verified) { $p->update(['verified' => true]); $p->activities()->create(['type' => 'system', 'body' => "Marked verified by $by"]); $n++; }
                break;
            case 'pull':
                if (count($ids) > self::PULL_LIMIT) {
                    Notification::make()->title('Pull details for at most ' . self::PULL_LIMIT . ' shops at a time')->warning()->send(); return;
                }
                if (! $this->confirmPull) { $this->confirmPull = true; return; }
                $this->confirmPull = false;
                foreach ($rows as $p) {
                    try { if (\App\Services\Sales\ProspectEnricher::enrich($p) !== 'no record') $n++; } catch (\Throwable $e) { report($e); }
                }
                break;
            case 'email': // MARKER-SALES-EMAIL
                $this->emailSelected($ids);
                return;
            default:
                return;
        }

        $this->selected = []; $this->bulkAction = ''; $this->bulkValue = '';
        Notification::make()->title("$n of " . count($ids) . ' updated')->success()->send();
    }

    public function cancelPull(): void { $this->confirmPull = false; }

    public function pullCostCents(): int
    {
        return count($this->selected) * \App\Models\SalesSetting::placesCostCents();
    }

    public function setIndustry(string $channelId): void
    {
        $p = $this->current(); if (! $p) return;
        $c = $channelId ? \App\Models\SalesChannel::find($channelId) : null;
        $p->update(['channel_id' => $c?->id]);
        $p->activities()->create(['type' => 'system', 'body' => 'Industry: ' . ($c?->name ?? 'none')]);
    }

    public function saveContact(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->validate(['contactName' => ['nullable', 'string', 'max:191'], 'contactEmail' => ['nullable', 'email', 'max:191']],
            ['contactEmail.email' => 'That email address doesn\'t look right.']);
        $p->update(['owner_contact' => trim($this->contactName) ?: null, 'email' => strtolower(trim($this->contactEmail)) ?: null]);
        Notification::make()->title('Contact saved')->success()->send();
    }

    // ---------------------------------------------------------------- MARKER-SALES-EMAIL
    // One-off email to a prospect, from the drawer. Goes out on the platform
    // broadcast stream with the unsubscribe link and postal address, honours
    // platform opt-outs, and lands on the prospect's timeline. Replies come
    // back into the platform inbox.
    public bool   $showEmail    = false;
    public string $emailSubject = '';
    public string $emailBody    = '';

    public function openEmail(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->showEmail = true;
        $this->emailSubject = '';
        $first = trim(explode(' ', (string) $p->owner_contact)[0] ?? '');
        $this->emailBody = ($first !== '' ? "Hi $first,\n\n" : "Hi,\n\n");
    }

    public function sendEmail(): void
    {
        $p = $this->current(); if (! $p) return;
        $this->validate(['emailSubject' => ['required', 'string', 'max:191'], 'emailBody' => ['required', 'string', 'max:10000']],
            ['emailSubject.required' => 'Add a subject.', 'emailBody.required' => 'Write the message.']);
        $to = strtolower(trim((string) $p->email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Notification::make()->title('Add an email address for this shop first')->warning()->send(); return;
        }
        if (\App\Models\PlatformEmailOptout::has($to)) {
            Notification::make()->title('Not sent: this address unsubscribed from Intake email')->warning()->send(); return;
        }

        $footer = '<p style="font-size:12px;color:#777;margin-top:28px">' . e(\App\Services\Platform\PlatformMailer::fromName())
            . (\App\Services\Platform\PlatformMailer::postalAddress() ? ' · ' . e(\App\Services\Platform\PlatformMailer::postalAddress()) : '')
            . '<br><a href="' . e(\App\Http\Controllers\Platform\PlatformUnsubscribeController::url($to)) . '" style="color:#777">Unsubscribe</a></p>';
        $html = '<div style="font-family:Inter,Arial,sans-serif;font-size:15px;line-height:1.6;color:#111">' . nl2br(e(trim($this->emailBody))) . $footer . '</div>';

        $thread = \App\Models\PlatformInboxMessage::create([
            'kind'    => \App\Models\PlatformInboxMessage::KIND_CONTACT,
            'status'  => 'archived',
            'name'    => $p->owner_contact ?: $p->shop,
            'email'   => $to,
            'company' => $p->shop,
            'subject' => trim($this->emailSubject),
            'body'    => 'Emailed from Prospects: ' . trim($this->emailBody),
            'meta'    => ['origin' => 'prospect_email', 'prospect' => $p->id],
        ]);

        try {
            $ok = \App\Services\Platform\PlatformMailer::send($to, $p->owner_contact ?: null, trim($this->emailSubject), $html,
                \App\Http\Controllers\Platform\PlatformUnsubscribeController::url($to), ['X-PM-Metadata-prospect' => $p->id], $thread->replyToken());
        } catch (\Throwable $e) {
            report($e);
            \App\Services\Platform\PlatformMailer::log('prospect', $to, trim($this->emailSubject), ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
            Notification::make()->title('Not sent')->body($e->getMessage())->danger()->send(); return;
        }
        if (! $ok) {
            $thread->delete();
            Notification::make()->title('Not sent: platform email has no broadcast stream set')->body('Set it on Platform email, then try again.')->warning()->send(); return;
        }

        \App\Services\Platform\PlatformMailer::log('prospect', $to, trim($this->emailSubject));
        $p->activities()->create(['type' => 'email', 'body' => 'Emailed: ' . trim($this->emailSubject) . ' (' . (Auth::user()?->name ?? 'staff') . ')']);
        $changes = ['last_contacted_at' => now()];
        if ($p->stage === 'prospect') $changes['stage'] = 'contacted';
        $p->update($changes);
        $this->showEmail = false; $this->emailSubject = ''; $this->emailBody = '';
        Notification::make()->title('Sent to ' . $to)->success()->send();
    }

    /** "Email selected": an audience of exactly these shops, then on to Platform email to write the campaign. */
    protected function emailSelected(array $ids): void
    {
        $withEmail = SalesProspect::query()->whereIn('id', $ids)->whereNotNull('email')->where('email', '!=', '')->count();
        if ($withEmail === 0) {
            Notification::make()->title('None of the selected shops has an email address')->warning()->send(); return;
        }
        $aud = \App\Models\PlatformAudience::create([
            'name'   => 'Prospects: ' . count($ids) . ' selected, ' . now()->format('M j, g:ia'),
            'source' => 'prospects',
            'rules'  => [['field' => 'ids', 'op' => 'is', 'value' => implode(',', $ids)]],
        ]);
        $this->selected = []; $this->bulkAction = '';
        Notification::make()->title("Audience \"{$aud->name}\" created")
            ->body("$withEmail of " . count($ids) . ' have an email address. Pick this audience when you create the campaign.')->success()->send();
        $this->redirect(\App\Filament\Pages\PlatformCommunication::getUrl() . '?tab=campaigns');
    }

    public function editUrl(string $id): string
    {
        return \App\Filament\Resources\SalesProspectResource::getUrl('edit', ['record' => $id]);
    }
}
