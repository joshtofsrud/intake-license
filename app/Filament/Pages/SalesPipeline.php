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
    protected static ?string $navigationIcon  = 'heroicon-o-view-columns';
    protected static ?string $navigationLabel = 'Pipeline';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 8;
    protected static string  $view            = 'filament.pages.sales-pipeline';
    protected static ?string $slug            = 'sales-pipeline';
    protected static ?string $title           = 'Pipeline';

    public const PER_COLUMN = 80;

    // filters
    public string $territoryId = '';
    public string $repId       = '';
    public string $priority    = '';
    public bool   $dueOnly     = false;
    public bool   $hideUntouched = true;
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
            ->when($this->territoryId === 'none', fn ($q) => $q->whereNull('territory_id'))
            ->when($this->territoryId && $this->territoryId !== 'none', fn ($q) => $q->where('territory_id', $this->territoryId))
            ->when($this->repId === 'none', fn ($q) => $q->whereNull('sales_rep_id'))
            ->when($this->repId && $this->repId !== 'none', fn ($q) => $q->where('sales_rep_id', $this->repId))
            ->when($this->priority, fn ($q) => $q->where('priority', $this->priority))
            ->when($this->dueOnly, fn ($q) => $q->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))
            ->when($this->hideUntouched, fn ($q) => $q->where(fn ($w) => $w->where('stage', '!=', 'prospect')->orWhereNotNull('last_contacted_at')->orWhereNotNull('next_action_on')->orWhereNotNull('sales_rep_id')))
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
        return $this->openId ? SalesProspect::with(['rep.agency', 'territory', 'tenant', 'activities'])->find($this->openId) : null;
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
    }

    public function close(): void { $this->openId = null; $this->showInvite = false; }

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

    public function editUrl(string $id): string
    {
        return \App\Filament\Resources\SalesProspectResource::getUrl('edit', ['record' => $id]);
    }
}
