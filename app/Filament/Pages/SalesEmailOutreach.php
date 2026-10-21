<?php

namespace App\Filament\Pages;

use App\Models\SalesEmailTemplate;
use App\Models\SalesProspect;
use App\Services\Sales\ProspectOutreach;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-EMAIL-OUTREACH: one prospect at a time, like Instagram follows. You
 * pick a template and the page their link opens, read it, and Send. Nothing
 * sends on its own. Template, landing page and filters are remembered per
 * admin (users.admin_prefs), so they stay put between shops and visits.
 */
class SalesEmailOutreach extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Email outreach';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 10;
    protected static string  $view            = 'filament.pages.sales-email-outreach';
    protected static ?string $slug            = 'sales-email-outreach';
    protected static ?string $title           = 'Email outreach';

    public const TZ = 'America/Los_Angeles';

    public string $industryId = '';
    public string $state = '';
    public string $stage = '';
    public string $sort = 'score';

    public string $templateId = '';
    public string $landing = '/';
    public string $subject = '';
    public string $body = '';
    public ?string $currentId = null;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $prefs = ProspectOutreach::prefs();
        foreach (['industryId', 'state', 'stage', 'sort', 'templateId'] as $k) {
            if (isset($prefs[$k]) && is_string($prefs[$k])) $this->{$k} = $prefs[$k];
        }
        if (isset($prefs['page']) && is_string($prefs['page'])) $this->landing = $prefs['page'];
        if ($this->templateId !== '' && ! SalesEmailTemplate::whereKey($this->templateId)->exists()) $this->templateId = '';
        if ($this->templateId === '') $this->templateId = (string) (SalesEmailTemplate::orderBy('sort')->orderBy('name')->value('id') ?? '');
        // first visit: start on the template's own page
        if (! isset($prefs['page']) && $this->templateId !== '') {
            $this->landing = (string) (SalesEmailTemplate::whereKey($this->templateId)->value('page') ?: '/');
        }
        $this->landing = ProspectOutreach::normalizePage($this->landing);
        $this->loadNext();
    }

    public function updated($name): void
    {
        if (in_array($name, ['industryId', 'state', 'stage', 'sort'], true)) {
            ProspectOutreach::savePrefs([$name => $this->{$name}]);
            $this->loadNext();
        } elseif ($name === 'templateId') {
            $tpl = $this->templateId !== '' ? SalesEmailTemplate::find($this->templateId) : null;
            if ($tpl && $tpl->page) $this->landing = ProspectOutreach::normalizePage($tpl->page);
            $this->applyTemplate();
            ProspectOutreach::savePrefs(['templateId' => $this->templateId, 'page' => $this->landing]);
        } elseif ($name === 'landing') {
            $this->landing = ProspectOutreach::normalizePage($this->landing);
            ProspectOutreach::savePrefs(['page' => $this->landing]);
        }
    }

    // ---------------------------------------------------------------- the queue

    private static function dayStart()
    {
        return now(self::TZ)->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** Open prospects with an email address, not emailed yet, not unsubscribed. */
    private function base()
    {
        return SalesProspect::query()
            ->whereNotNull('email')->where('email', 'like', '%@%')
            ->whereNull('emailed_at')
            ->whereNotIn('stage', ['won', 'lost'])
            ->whereRaw('LOWER(email) NOT IN (SELECT email FROM platform_email_optouts)');
    }

    private function filtered()
    {
        return $this->base()
            ->when($this->industryId !== '', fn ($q) => $q->where('channel_id', $this->industryId))
            ->when($this->state !== '', fn ($q) => $q->where('state', $this->state))
            ->when($this->stage !== '', fn ($q) => $q->where('stage', $this->stage));
    }

    private function ordered()
    {
        // skipped ones go to the back, oldest skip first
        $q = $this->filtered()->orderByRaw('email_skipped_at IS NOT NULL')->orderBy('email_skipped_at');
        return $this->sort === 'new' ? $q->orderByDesc('created_at') : $q->orderByDesc('lead_score')->orderBy('shop');
    }

    public function industries(): array
    {
        $counts = $this->base()->selectRaw('channel_id, COUNT(*) n')->groupBy('channel_id')->pluck('n', 'channel_id');
        return \App\Models\SalesChannel::query()->whereIn('id', $counts->keys()->filter())->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'n' => (int) ($counts[$c->id] ?? 0)])
            ->sortByDesc('n')->values()->all();
    }

    public function states(): array
    {
        return $this->base()->whereNotNull('state')->distinct()->orderBy('state')->pluck('state')->all();
    }

    public function stats(): array
    {
        return [
            'today'   => DB::table('sales_outreach_sends')->where('created_at', '>=', self::dayStart())->count(),
            'all'     => DB::table('sales_outreach_sends')->count(),
            'visited' => SalesProspect::query()->whereNotNull('link_clicked_at')->count(),
            'queue'   => (clone $this->filtered())->count(),
        ];
    }

    public function queue()
    {
        return $this->ordered()->where('id', '!=', (string) $this->currentId)->limit(25)->get(['id', 'shop', 'city', 'state', 'email', 'lead_score', 'email_skipped_at']);
    }

    public function sentToday()
    {
        return DB::table('sales_outreach_sends as s')
            ->join('sales_prospects as p', 'p.id', '=', 's.sales_prospect_id')
            ->where('s.created_at', '>=', self::dayStart())
            ->orderByDesc('s.created_at')->limit(40)
            ->get(['p.shop', 's.created_at', 'p.link_clicked_at']);
    }

    public function current(): ?SalesProspect
    {
        return $this->currentId ? SalesProspect::with('channel:id,name')->find($this->currentId) : null;
    }

    private function loadNext(): void
    {
        $next = $this->ordered()->first(['id']);
        $id = $next?->id ? (string) $next->id : null;
        if ($id !== $this->currentId) {
            $this->currentId = $id;
            $this->applyTemplate();
        }
    }

    private function applyTemplate(): void
    {
        $tpl = $this->templateId !== '' ? SalesEmailTemplate::find($this->templateId) : null;
        $this->subject = (string) ($tpl?->subject ?? '');
        $this->body    = (string) ($tpl?->body ?? "Hi [first_name],\n\n[link]\n\n");
        $this->resetErrorBag();
    }

    // ---------------------------------------------------------------- for the view

    public function templates()
    {
        return SalesEmailTemplate::orderBy('sort')->orderBy('name')->get(['id', 'name']);
    }

    public function landingPages(): array
    {
        return ProspectOutreach::landingPages();
    }

    public function previewHtml(SalesProspect $p): string
    {
        return ProspectOutreach::html($this->body, $p, $this->landing, mb_strtolower(trim((string) $p->email)));
    }

    public function previewSubject(SalesProspect $p): string
    {
        return ProspectOutreach::subject($this->subject, $p);
    }

    public function link(SalesProspect $p): string
    {
        return ProspectOutreach::link($p, $this->landing);
    }

    // ---------------------------------------------------------------- actions

    public function send(): void
    {
        $p = $this->current();
        if (! $p) return;
        $this->validate(
            ['subject' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:10000']],
            ['subject.required' => 'Add a subject.', 'body.required' => 'Write the message.']
        );
        [$ok, $msg] = ProspectOutreach::send($p, $this->subject, $this->body, $this->landing, $this->templateId ?: null);
        if (! $ok) {
            Notification::make()->title($msg)->warning()->send();
            return;
        }
        Notification::make()->title($msg)->success()->send();
        $this->currentId = null;
        $this->loadNext();
    }

    /** MARKER-OUTREACH-TEST-SIG: the same email, only to you. The shop stays in the queue. */
    public function sendTest(): void
    {
        $p = $this->current();
        if (! $p) return;
        $this->validate(
            ['subject' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:10000']],
            ['subject.required' => 'Add a subject.', 'body.required' => 'Write the message.']
        );
        [$ok, $msg] = ProspectOutreach::sendTest($p, $this->subject, $this->body, $this->landing);
        Notification::make()->title($msg)->{$ok ? 'success' : 'warning'}()->send();
    }

    public function skip(): void
    {
        $p = $this->current();
        if ($p) $p->forceFill(['email_skipped_at' => now()])->save();
        $this->currentId = null;
        $this->loadNext();
    }

    /** The address is wrong: take it off so it can be fixed on the prospect. */
    public function wrongEmail(): void
    {
        $p = $this->current();
        if ($p) {
            $old = (string) $p->email;
            $p->forceFill(['email' => null])->save();
            $p->activities()->create(['type' => 'system', 'body' => 'Email address removed as wrong: ' . $old, 'occurred_at' => now()]);
        }
        $this->currentId = null;
        $this->loadNext();
    }
}
