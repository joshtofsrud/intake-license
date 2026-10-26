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
    public string $who = 'new'; // MARKER-OUTREACH-RECONTACT: new | again | all
    public string $mail = 'any'; // MARKER-OUTREACH-AUTO: any | business | free

    // MARKER-OUTREACH-AUTO: auto-send's form (the run itself lives in OutreachAutoSend)
    public int $gapBusiness = 10;
    public int $gapFree = 60;
    public int $capBusiness = 0;
    public int $capFree = 0;
    public int $maxBounces = 5;
    public string $freeDomainsText = '';
    public bool $showDomains = false;

    public string $templateId = '';
    public string $landing = '/';
    public string $subject = '';
    public string $body = '';
    public ?string $currentId = null;

    // MARKER-OUTREACH-RESULTS: Send | Results, and the Results filters
    public string $tab = 'send';
    public string $rIndustry = '';
    public string $rTemplate = '';
    public string $rDays = '30';
    public ?string $rFrom = null;   // MARKER-OUTREACH-RANGE: picked with the calendar
    public ?string $rTo   = null;
    public ?string $drill = null; // MARKER-OUTREACH-DRILL: which funnel number is open

    /** Apply / Clear from the calendar on Results. */
    public function setRange(?string $from, ?string $to): void
    {
        $ok = fn (?string $d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        $this->rFrom = $ok($from);
        $this->rTo   = $ok($to) ?? $this->rFrom;
        $this->rDays = $this->rFrom ? 'custom' : '30';
        $this->drill = null;
    }

    // MARKER-OUTREACH-LAYOUT: the list under the composer (Queue | Emailed today | Visited), 10 a page
    public string $list = 'queue';
    public int $listPage = 1;
    public const PER_PAGE = 10;

    public function setList(string $l): void
    {
        $this->list = in_array($l, ['queue', 'today', 'visited'], true) ? $l : 'queue';
        $this->listPage = 1;
    }

    public function listPageTo(int $p): void
    {
        $this->listPage = max(1, $p);
    }

    /** True while the shop on screen is the top of the queue (not one picked from the list). */
    public function onTop(): bool
    {
        return $this->currentId !== null && ($this->topIds()[0] ?? null) === (string) $this->currentId;
    }

    /** Write to a shop from the queue instead of the one on top. */
    public function pick(string $id): void
    {
        if (! $this->filtered()->whereKey($id)->exists()) return;
        $this->currentId = $id;
        $this->applyTemplate();
    }

    public function toggleDrill(string $k): void
    {
        $this->drill = $this->drill === $k ? null : $k;
    }

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (request()->query('tab') === 'results') $this->tab = 'results';
        $prefs = ProspectOutreach::prefs();
        foreach (['industryId', 'state', 'stage', 'sort', 'templateId', 'who', 'mail'] as $k) {
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
        if (! isset(\App\Services\Sales\OutreachQueue::MAIL[$this->mail])) $this->mail = 'any';
        $d = \App\Services\Sales\OutreachAutoSend::defaults();
        [$this->gapBusiness, $this->gapFree, $this->capBusiness, $this->capFree, $this->maxBounces]
            = [$d['gap_business'], $d['gap_free'], $d['cap_business'], $d['cap_free'], $d['max_bounces']];
        $this->freeDomainsText = implode("\n", \App\Services\Sales\OutreachQueue::freeDomains());
        $this->loadNext();
    }

    public function updated($name): void
    {
        if ($name === 'mail' && ! isset(\App\Services\Sales\OutreachQueue::MAIL[$this->mail])) $this->mail = 'any';
        if (in_array($name, ['industryId', 'state', 'stage', 'sort', 'who', 'mail'], true)) {
            ProspectOutreach::savePrefs([$name => $this->{$name}]);
            $this->listPage = 1;
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

    // ---------------------------------------------------------------- results

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'results' ? 'results' : 'send';
    }

    public function results(): array
    {
        return \App\Services\Sales\OutreachResults::compute([
            'industry' => $this->rIndustry,
            'template' => $this->rTemplate,
            'days'     => in_array($this->rDays, ['today', '7', '30', '90', 'all', 'custom'], true) ? $this->rDays : '30',
            'from'     => $this->rFrom,
            'to'       => $this->rTo,
            'tz'       => self::TZ,
        ]);
    }

    // ---------------------------------------------------------------- the queue

    private static function dayStart()
    {
        return now(self::TZ)->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** MARKER-OUTREACH-AUTO: the filters on screen, as OutreachQueue takes them. */
    public function filters(): array
    {
        return ['who' => $this->who, 'industryId' => $this->industryId, 'state' => $this->state, 'stage' => $this->stage,
            'sort' => $this->sort, 'mail' => $this->mail];
    }

    /** Open prospects with an email address that can be emailed now (no filters but who). */
    private function base()
    {
        return \App\Services\Sales\OutreachQueue::filtered($this->filters() + ['_base' => true]);
    }

    private function filtered()
    {
        return \App\Services\Sales\OutreachQueue::filtered($this->filters());
    }

    private function ordered()
    {
        return \App\Services\Sales\OutreachQueue::ordered($this->filters());
    }

    /**
     * The next 26 ids in queue order, read from the index alone and fetched once per click:
     * the first is the shop on screen, the rest are the queue list.
     */
    private ?array $topIds = null;
    private function topIds(bool $fresh = false): array
    {
        if ($fresh || $this->topIds === null) {
            $this->topIds = $this->ordered()->limit(26)->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
        return $this->topIds;
    }

    /** Cached for a minute: they hardly move between sends, and each is a pass over every shop. */
    private function cached(string $what, \Closure $fn)
    {
        return \Illuminate\Support\Facades\Cache::remember('outreach:' . $what . ':' . md5(json_encode([$this->who, $this->industryId, $this->state, $this->stage, $this->mail])), 60, $fn);
    }

    public function industries(): array
    {
        return $this->cached('industries', function () {
        // every industry with an emailable shop keeps its tab, even at 0 for this filter
        $counts = $this->base()->selectRaw('channel_id, COUNT(*) n')->groupBy('channel_id')->pluck('n', 'channel_id');
        $any = SalesProspect::query()->whereNotNull('email')->where('email', 'like', '%@%')->whereNotIn('stage', ['won', 'lost'])
            ->whereNotNull('channel_id')->distinct()->pluck('channel_id');
        return \App\Models\SalesChannel::query()->whereIn('id', $any)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'n' => (int) ($counts[$c->id] ?? 0)])
            ->sortByDesc('n')->values()->all();
        });
    }

    public function states(): array
    {
        // MARKER-OUTREACH-STATE-LIST: states with shops in the industry that's open
        return $this->cached('states', fn () => $this->base()
            ->when($this->industryId !== '', fn ($q) => $q->where('channel_id', $this->industryId))
            ->whereNotNull('state')->distinct()->orderBy('state')->pluck('state')->all());
    }

    /** MARKER-OUTREACH-TALLY-GROUP: the tally follows the industry tab that's open. */
    public function stats(): array
    {
        $sends = fn () => DB::table('sales_outreach_sends as s')
            ->when($this->industryId !== '', fn ($q) => $q->join('sales_prospects as p', 'p.id', '=', 's.sales_prospect_id')
                ->where('p.channel_id', $this->industryId));
        return [
            'today'   => $sends()->where('s.created_at', '>=', self::dayStart())->count(),
            'all'     => $sends()->count(),
            'visited' => SalesProspect::query()->whereNotNull('link_clicked_at')
                ->when($this->industryId !== '', fn ($q) => $q->where('channel_id', $this->industryId))->count(),
            'queue'   => (clone $this->filtered())->count(),
            'total'   => $this->base()->count(),
        ];
    }

    public function queue()
    {
        // MARKER-OUTREACH-LAYOUT: page 1 comes from the ids already read; later pages ask for theirs
        $per = self::PER_PAGE;
        if ($this->listPage <= 2) {
            $ids = array_slice(array_values(array_diff($this->topIds(), [(string) $this->currentId])), ($this->listPage - 1) * $per, $per);
        } else {
            $ids = $this->ordered()->whereKeyNot((string) $this->currentId)->skip(($this->listPage - 1) * $per)->limit($per)->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
        if (! $ids) return collect();
        $rows = SalesProspect::query()->whereIn('id', $ids)->get(['id', 'shop', 'city', 'state', 'email', 'lead_score', 'email_skipped_at', 'emailed_at'])->keyBy('id');
        return collect($ids)->map(fn ($id) => $rows[$id] ?? null)->filter()->values();
    }

    public function sentToday()
    {
        return DB::table('sales_outreach_sends as s')
            ->join('sales_prospects as p', 'p.id', '=', 's.sales_prospect_id')
            ->where('s.created_at', '>=', self::dayStart())
            ->when($this->industryId !== '', fn ($q) => $q->where('p.channel_id', $this->industryId))
            ->orderByDesc('s.created_at')
            ->skip(($this->listPage - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['p.shop', 'p.city', 'p.state', 's.created_at', 'p.link_clicked_at', 's.thread_id']);
    }

    /**
     * MARKER-OUTREACH-OPENS: for each sent email's thread, when the provider saw
     * an open (or a click) and when they first replied. Auto-replies are flagged
     * apart, never counted as a reply. Same sources as Results.
     */
    public function threadNews($rows): array
    {
        $ids = collect($rows)->pluck('thread_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        $out = [];
        if (! $ids) return $out;
        foreach (\App\Models\PlatformInboxMessage::whereIn('id', $ids)->get(['id', 'meta']) as $m) {
            $meta = (array) ($m->meta ?? []);
            $o = $meta['opened_at'] ?? ($meta['clicked_at'] ?? null);
            if ($o) $out[(string) $m->id]['opened'] = \Carbon\Carbon::parse($o);
        }
        foreach (DB::table('platform_inbox_replies')->whereIn('message_id', $ids)->where('direction', 'in')
            ->orderBy('created_at')->get(['message_id', 'created_at', 'is_auto']) as $r) {
            $k = $r->is_auto ? 'auto' : 'replied';
            $out[(string) $r->message_id][$k] ??= \Carbon\Carbon::parse($r->created_at);
        }
        return $out;
    }

    /** MARKER-OUTREACH-LAYOUT: shops that opened their link, latest first. */
    public function visited()
    {
        return SalesProspect::query()->whereNotNull('link_clicked_at')
            ->when($this->industryId !== '', fn ($q) => $q->where('channel_id', $this->industryId))
            ->orderByDesc('link_clicked_at')
            ->skip(($this->listPage - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['id', 'shop', 'city', 'state', 'emailed_at', 'link_clicked_at']);
    }

    public function current(): ?SalesProspect
    {
        return $this->currentId ? SalesProspect::with('channel:id,name')->find($this->currentId) : null;
    }

    private function loadNext(): void
    {
        $id = $this->topIds(true)[0] ?? null;
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

    // ---------------------------------------------------------------- MARKER-OUTREACH-AUTO

    public function autoState(): array
    {
        $s = \App\Services\Sales\OutreachAutoSend::state();
        if ($s['running'] ?? false) $s['bounces'] = \App\Services\Sales\OutreachAutoSend::bounces($s);

        return $s;
    }

    public function startAuto(): void
    {
        $u = Auth::guard('web')->user();
        try {
            \App\Services\Sales\OutreachAutoSend::start($this->filters(), $this->templateId, $this->landing, [
                'gap_business' => $this->gapBusiness, 'gap_free' => $this->gapFree,
                'cap_business' => $this->capBusiness, 'cap_free' => $this->capFree, 'max_bounces' => $this->maxBounces,
            ], (int) $u->id, (string) $u->name);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
            return;
        }
        Notification::make()->title('Auto-send started')->body('It keeps going if you close this page. Stop it here any time.')->success()->send();
    }

    public function stopAuto(): void
    {
        \App\Services\Sales\OutreachAutoSend::stop('Stopped by ' . (Auth::guard('web')->user()?->name ?? 'an admin') . '.');
        Notification::make()->title('Auto-send stopped')->success()->send();
        $this->loadNext();
    }

    public function saveFreeDomains(): void
    {
        $list = \App\Services\Sales\OutreachQueue::saveFreeDomains($this->freeDomainsText);
        $this->freeDomainsText = implode("\n", $list);
        Notification::make()->title(count($list) . ' free email domains saved')->success()->send();
        $this->loadNext();
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
