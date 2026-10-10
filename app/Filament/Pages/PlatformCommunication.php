<?php

namespace App\Filament\Pages;

use App\Models\PlatformEmailTemplate;
use App\Models\PlatformSettings;
use App\Support\PlatformEmailTemplates;
use App\Services\Platform\PlatformMailer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;

/**
 * one place for every message Intake itself sends.
 *
 * Built in the tenant Communication page's vocabulary deliberately: sender line
 * on top, messages grouped, per-message Edit. Only the tabs that work exist —
 * Inbound, Activity and Suppressions arrive with their own patches.
 */
class PlatformCommunication extends Page
{
    use \App\Support\UsesAdminNav;
    use \Livewire\WithFileUploads; // screenshots

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Communication';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int    $navigationSort  = 6;
    protected static string  $view            = 'filament.pages.platform-communication';
    protected static ?string $slug            = 'communication';

    #[Url(as: 'tab', keep: true)]
    public string $tab = 'messages';        // messages | campaigns

    #[Url(as: 'edit')]
    public ?string $editing = null;

    #[Url(as: 'campaign')]
    public ?string $campaignId = null;

    public string $cName = '';
    public string $cSubject = '';
    public string $cBody = '';
    public string $cAudience = '';
    public string $cSchedule = '';
    // a campaign is plain text (body) or letter blocks
    public string $cMode = 'body';
    public array $cBlocks = [];
    public string $cTheme = 'light';
    public array $shotUploads = [];

    public string $aName = '';
    public string $aSource = 'tenants';
    public string $aField = '';
    public string $aOp = 'is';
    public string $aValue = '';
    // open an audience to edit it; delete audiences and campaigns
    public ?string $aEditing = null;
    public array   $aRules   = [];
    public array   $aExclude = []; // MARKER-AUDIENCE-BUILDER prospect exclusions ticked
    public ?string $confirmDel = null; // 'a:<id>' or 'c:<id>' while the inline "Delete?" is showing

    public string $subject = '';
    public string $body    = '';
    public string $testTo  = '';

    public function mount(): void
    {
        if ($this->editing) {
            $this->loadTemplate($this->editing);
        }
    }

    public function setTab(string $tab): void
    {
        // four tabs now, all of them real.
        $this->tab = in_array($tab, ['messages', 'campaigns', 'activity', 'suppressions'], true)
            ? $tab
            : 'messages';
    }

    /**
     * lift a block that stops every shop's mail to an
     * address. Only the all-shops row goes; each shop's own block stays.
     */
    public function unblockAllShops(int $id): void
    {
        $row = \App\Models\Tenant\TenantEmailSuppression::whereNull('tenant_id')->whereKey($id)->first();
        if (! $row) {
            Notification::make()->warning()->title('Already unblocked')->send();
            return;
        }
        $email = $row->email;
        $row->delete();

        Notification::make()->success()
            ->title('Unblocked for all shops')
            ->body($email . ' can get mail from shops again. Shops that blocked it themselves still do, and another bounce blocks it again.')
            ->send();
    }

    /** let an address back in. */
    public function unsuppress(string $email): void
    {
        \App\Models\PlatformEmailOptout::whereKey($email)->delete();

        Notification::make()->success()
            ->title('Removed from the suppression list')
            ->body($email . ' can be mailed again. If it bounced before, it may bounce again.')
            ->send();
    }

    protected function getViewData(): array
    {
        $overrides = [];
        try {
            $overrides = PlatformEmailTemplate::all()->keyBy('key')->all();
        } catch (\Throwable $e) {
            // migration not run yet
        }

        $groups = [];
        foreach (PlatformEmailTemplates::REGISTRY as $key => $meta) {
            $groups[$meta['group']][$key] = $meta + ['override' => $overrides[$key] ?? null];
        }

        // campaign data, and the reach figure
        // computed by the SAME resolver the worker uses, so the number on the
        // screen is the number that gets mailed.
        $audiences = collect();
        $campaigns = collect();
        $campaign  = null;
        $reach     = null;

        try {
            $audiences = \App\Models\PlatformAudience::orderBy('name')->get();
            $campaigns = \App\Models\PlatformCampaign::with('audience')->latest()->limit(50)->get();
            $campaign  = $this->campaignId ? \App\Models\PlatformCampaign::find($this->campaignId) : null;

            if ($campaign && $campaign->audience) {
                $svc   = app(\App\Services\Platform\PlatformAudienceService::class);
                $all   = $svc->resolve($campaign->audience);
                $ok    = $svc->mailable($campaign->audience);
                $reach = [
                    'matched'  => $all->count(),
                    'mailable' => $ok->count(),
                    'names'    => $ok->take(6)->pluck('name')->filter()->all(),
                ];
            }
        } catch (\Throwable $e) {
            // migration not run yet
        }

        // the senders this page cannot edit,
        // grouped the same way so the list reads as one thing.
        $others = [];
        foreach (PlatformEmailTemplates::OTHER_SENDERS as $row) {
            $others[$row['group']][] = $row;
        }

        // the log is campaign sends and one-off sends
        // read together, newest first, rather than a second copy of either.
        $activity = collect();
        $suppressions = collect();
        $suppressCounts = ['unsubscribe' => 0, 'bounce' => 0, 'complaint' => 0];
        $allShops = collect();

        try {
            $ones = \App\Models\PlatformEmailSend::latest()->limit(100)->get()->map(fn ($r) => [
                'when'    => $r->created_at,
                'email'   => $r->email,
                'what'    => $r->subject ?: ucfirst($r->kind),
                'kind'    => $r->kind,
                'status'  => $r->status,
            ]);

            $camp = \App\Models\PlatformCampaignSend::with([])->latest()->limit(100)->get()->map(fn ($r) => [
                'when'    => $r->sent_at ?: $r->created_at,
                'email'   => $r->email,
                'what'    => 'Campaign',
                'kind'    => 'campaign',
                'status'  => $r->status,
            ]);

            $activity = $ones->concat($camp)->sortByDesc('when')->take(100)->values();

            $suppressions = \App\Models\PlatformEmailOptout::latest('updated_at')->limit(200)->get();

            // blocks with no shop attached stop every
            // shop's mail to that address. They were only visible as a count.
            $allShops = \App\Models\Tenant\TenantEmailSuppression::whereNull('tenant_id')
                ->orderByDesc('suppressed_at')->limit(200)->get();
            foreach ($suppressions as $row) {
                $k = $row->kind ?: 'unsubscribe';
                $suppressCounts[$k] = ($suppressCounts[$k] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            // migration not run yet
        }

        return [
            'activity'       => $activity,
            'suppressions'   => $suppressions,
            'suppressCounts' => $suppressCounts,
            'allShops'       => $allShops,
            'others'     => $others,
            'audiences'  => $audiences,
            'aScreen'    => $this->tab === 'campaigns' ? $this->audienceScreen() : null,
            'campaigns'  => $campaigns,
            'campaign'   => $campaign,
            'reach'      => $reach,
            'blockers'   => $campaign ? $this->blockers($campaign, $reach) : [],
            // the preview uses sample values, in the chosen theme
            'letterPreview' => ($campaign && $this->cMode === 'letter')
                ? \App\Support\PlatformLetter::render($this->cBlocks, \App\Support\PlatformLetter::sampleVars(),
                    $this->cTheme === 'dark' ? 'dark' : 'light',
                    \App\Support\PlatformLetter::footerFor(optional($campaign->audience)->source), '#')
                : null,
            'letterTypes' => \App\Support\PlatformLetter::TYPES,
            'streamOk'   => (bool) PlatformMailer::stream(),
            'groups'     => $groups,
            'editingKey' => $this->editing,
            'meta'       => $this->editing ? (PlatformEmailTemplates::REGISTRY[$this->editing] ?? null) : null,
            'preview'    => $this->editing ? $this->previewHtml() : null,
            'fromLine'   => PlatformMailer::fromName() . ' · ' . PlatformMailer::fromAddress(),
            'replyLine'  => PlatformMailer::replyTo() ?: PlatformMailer::fromAddress(),
            'inboundOk'  => (bool) PlatformMailer::replyTo(),
        ];
    }

    // -------------------------------------------------------------- actions

    public function edit(string $key): void
    {
        if (! isset(PlatformEmailTemplates::REGISTRY[$key])) {
            return;
        }
        $this->editing = $key;
        $this->loadTemplate($key);
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->subject = '';
        $this->body    = '';
    }

    protected function loadTemplate(string $key): void
    {
        $row = PlatformEmailTemplate::find($key);
        $this->subject = $row->subject ?? (PlatformEmailTemplates::REGISTRY[$key]['subject'] ?? '');
        $this->body    = $row->body ?? '';
    }

    public function save(): void
    {
        if (! $this->editing) {
            return;
        }

        if (trim($this->body) === '') {
            Notification::make()->warning()
                ->title('Nothing to save')
                ->body('An empty body would send an empty email. Use Revert to go back to the built-in one.')
                ->send();
            return;
        }

        PlatformEmailTemplate::updateOrCreate(
            ['key' => $this->editing],
            [
                'subject'    => trim($this->subject) ?: null,
                'body'       => $this->body,
                'enabled'    => true,
                'updated_by' => (string) (auth()->user()->email ?? 'admin'),
            ]
        );

        Notification::make()->success()->title('Saved')->send();
    }

    /** Delete the override so the shipped Blade is authoritative again. */
    public function revert(): void
    {
        if (! $this->editing) {
            return;
        }

        PlatformEmailTemplate::where('key', $this->editing)->delete();
        $this->loadTemplate($this->editing);

        Notification::make()->success()
            ->title('Back to the built-in version')
            ->body('Your custom copy was deleted. The email that ships with Intake sends again.')
            ->send();
    }

    public function sendTest(): void
    {
        $to = trim($this->testTo);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Notification::make()->warning()->title('Enter a valid address first')->send();
            return;
        }

        try {
            $html    = $this->previewHtml();
            $vars    = PlatformEmailTemplates::sampleVars((string) $this->editing);
            $subject = PlatformEmailTemplates::merge(
                $this->subject ?: (PlatformEmailTemplates::REGISTRY[$this->editing]['subject'] ?? 'Intake'),
                $vars
            );

            Mail::html($html, function ($m) use ($to, $subject) {
                $m->to($to)->subject('[TEST] ' . $subject);
                if ($from = PlatformMailer::fromAddress()) {
                    $m->from($from, PlatformMailer::fromName());
                }
            });

            PlatformMailer::log('test', $to, '[TEST] ' . $subject, ['template_key' => $this->editing]);

            Notification::make()->success()->title('Test sent to ' . $to)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
        }
    }

    /** What the current editor state would actually look like. */
    protected function previewHtml(): string
    {
        $vars = PlatformEmailTemplates::sampleVars((string) $this->editing);

        // empty-body placeholder. Chrome wrapped
        // around nothing looked broken rather than empty, which sent Josh
        // looking for a bug that wasn't there.
        if (trim($this->body) === '') {
            return view('emails.platform.custom', [
                'bodyHtml' => '<span style="color:#aaa;font-style:italic;">Nothing written yet — what you type '
                    . 'appears here, with sample values filled in.</span>',
                'postal'   => PlatformMailer::postalAddress(),
            ])->render();
        }

        return view('emails.platform.custom', [
            'bodyHtml' => nl2br(e(PlatformEmailTemplates::merge($this->body, $vars))),
            'postal'   => PlatformMailer::postalAddress(),
        ])->render();
    }

    // ------------------------------------------------------------------
    // audiences and campaigns
    // ------------------------------------------------------------------

    /**
     * Everything standing between this campaign and a send, in plain language.
     * Computed in one place so the button and the reasons can never disagree.
     */
    protected function blockers(\App\Models\PlatformCampaign $c, ?array $reach): array
    {
        $out = [];

        if (! PlatformMailer::stream()) {
            $out[] = 'No platform broadcast stream is set, so nothing can send.';
        }
        // judged on what's in the editor now, not the
        // last saved draft (Send saves first, so the two agree when it matters).
        if (! ($this->cAudience ?: $c->audience_id)) {
            $out[] = 'Pick an audience.';
        }
        if (trim($this->cSubject) === '' && trim((string) $c->subject) === '') {
            $out[] = 'Write a subject.';
        }
        if ($this->cMode === 'letter') {
            $words = '';
            foreach ($this->cBlocks as $b) {
                if (($b['type'] ?? '') === 'letter_text') {
                    // the starter greeting on its own isn't a letter
                    $words .= preg_replace('/^\s*hi\s*\{+\s*first_name\s*\}+,?/i', '', (string) ($b['data']['text'] ?? ''));
                }
            }
            if (trim($words) === '') {
                $out[] = 'Write the letter.';
            }
        } elseif (trim($this->cBody) === '' && trim((string) $c->body) === '') {
            $out[] = 'Write a body.';
        }
        // MARKER-AUDIENCE-BUILDER: investor details never leave the investor channel.
        $src  = optional(\App\Models\PlatformAudience::find($this->cAudience ?: $c->audience_id))->source;
        $text = $this->cSubject . ' ' . $this->cBody . ' ' . json_encode($this->cBlocks);
        if ($src !== 'investors' && preg_match('/\{+\s*(amount|percent|remaining|portal)\s*\}+/i', $text)) {
            $out[] = 'This uses investor details ({amount}, {percent}, {remaining} or {portal}), which only go to an Investors audience.';
        }
        if ($reach !== null && $reach['mailable'] === 0) {
            $out[] = 'This audience matches nobody who can be mailed right now.';
        }

        return $out;
    }

    public function newAudience(): void
    {
        // MARKER-AUDIENCE-BUILDER: rule rows + exclusion ticks, named for you if left blank.
        $svc   = app(\App\Services\Platform\PlatformAudienceService::class);
        $rules = $this->audienceRules();
        $name  = trim($this->aName);
        if ($name === '') {
            $label = \App\Models\PlatformAudience::SOURCES[$this->aSource] ?? $this->aSource;
            $bits  = collect($rules)->reject(fn ($r) => ! empty($r['x']))
                ->map(fn ($r) => (($r['op'] ?? 'is') === 'is_not' || ($r['op'] ?? '') === 'has_not' ? 'not ' : '') . ($r['value'] ?? ''))->filter()->all();
            $name  = $label . ($bits ? ' - ' . implode(', ', $bits) : '');
        }

        $existing = $this->aEditing ? \App\Models\PlatformAudience::find($this->aEditing) : null;
        if ($existing) {
            $existing->update(['name' => $name, 'source' => $this->aSource, 'rules' => $rules]);
        } else {
            \App\Models\PlatformAudience::create(['name' => $name, 'source' => $this->aSource, 'rules' => $rules]);
        }

        $this->closeAudience();

        Notification::make()->success()->title($existing ? 'Audience updated' : 'Audience saved')->send();
    }

    /** The rules exactly as they'd be saved: filled rows, then the ticked exclusions (prospects only). */
    protected function audienceRules(): array
    {
        $rules = collect($this->aRules)
            ->filter(fn ($r) => is_array($r) && ($r['field'] ?? '') !== '' && trim((string) ($r['value'] ?? '')) !== '')
            ->map(fn ($r) => ['field' => (string) $r['field'], 'op' => (string) ($r['op'] ?? 'is'), 'value' => trim((string) $r['value'])])
            ->values()->all();
        if ($this->aSource === 'prospects') {
            foreach (\App\Services\Platform\PlatformAudienceService::EXCLUSIONS as $k => $ex) {
                if (in_array($k, $this->aExclude, true)) $rules[] = $ex['rule'];
            }
        }
        return $rules;
    }

    public function setSource(string $source): void
    {
        if (! array_key_exists($source, \App\Models\PlatformAudience::SOURCES)) return;
        $this->aSource  = $source;
        $this->aRules   = [];
        $this->aExclude = $source === 'prospects' ? array_keys(\App\Services\Platform\PlatformAudienceService::EXCLUSIONS) : [];
    }

    public function addRule(): void
    {
        $fields = \App\Services\Platform\PlatformAudienceService::FIELDS[$this->aSource] ?? [];
        if (! $fields) return;
        $used = collect($this->aRules)->pluck('field')->all();
        $key  = collect(array_keys($fields))->first(fn ($k) => ! in_array($k, $used, true)) ?? array_key_first($fields);
        $opts = app(\App\Services\Platform\PlatformAudienceService::class)->options($this->aSource)[$key] ?? [];
        $this->aRules[] = ['field' => $key, 'op' => $key === 'addon' ? 'has' : 'is', 'value' => (string) (array_key_first($opts) ?? '')];
    }

    public function toggleExclude(string $key): void
    {
        $this->aExclude = in_array($key, $this->aExclude, true)
            ? array_values(array_diff($this->aExclude, [$key]))
            : array_values(array_merge($this->aExclude, [$key]));
    }

    /** Changing a rule's field resets its operator and value to that field's first choices. */
    public function updatedARules($value, $key): void
    {
        [$i, $part] = array_pad(explode('.', (string) $key), 2, null);
        if ($part !== 'field' || ! isset($this->aRules[$i])) return;
        $f    = (string) $this->aRules[$i]['field'];
        $opts = app(\App\Services\Platform\PlatformAudienceService::class)->options($this->aSource)[$f] ?? [];
        $this->aRules[$i]['op']    = $f === 'addon' ? 'has' : 'is';
        $this->aRules[$i]['value'] = (string) (array_key_first($opts) ?? '');
    }

    /** Fields, value lists and the live preview for the audience being built. Never throws into the page. */
    protected function audienceScreen(): array
    {
        try {
            $svc = app(\App\Services\Platform\PlatformAudienceService::class);
            return [
                'fields'  => \App\Services\Platform\PlatformAudienceService::FIELDS[$this->aSource] ?? [],
                'options' => $svc->options($this->aSource),
                'preview' => $svc->preview($this->aSource, $this->audienceRules()),
            ];
        } catch (\Throwable $e) {
            return ['fields' => [], 'options' => [], 'preview' => null];
        }
    }

    // ----------------------------------------------
    public function openAudience(string $id): void
    {
        $a = \App\Models\PlatformAudience::find($id);
        if (! $a) return;
        $this->aEditing = $a->id;
        $this->aName    = (string) $a->name;
        $this->aSource  = (string) $a->source;
        $rules = array_values((array) ($a->rules ?? []));
        $this->aExclude = collect($rules)->pluck('x')->filter()->values()->all();
        $this->aRules   = collect($rules)->filter(fn ($r) => empty($r['x']))->map(fn ($r) => [
            'field' => (string) ($r['field'] ?? ''), 'op' => (string) ($r['op'] ?? 'is'),
            'value' => is_array($r['value'] ?? null) ? implode(',', $r['value']) : (string) ($r['value'] ?? ''),
        ])->values()->all();
        $this->aField = ''; $this->aOp = 'is'; $this->aValue = '';
        $this->confirmDel = null;
    }

    public function closeAudience(): void
    {
        $this->aEditing = null;
        $this->aRules = [];
        $this->aExclude = [];
        $this->aName = ''; $this->aSource = 'tenants';
        $this->aField = ''; $this->aOp = 'is'; $this->aValue = '';
    }

    public function removeAudienceRule(int $i): void
    {
        unset($this->aRules[$i]);
        $this->aRules = array_values($this->aRules);
    }

    public function askDelete(string $key): void { $this->confirmDel = $key; }
    public function cancelDelete(): void          { $this->confirmDel = null; }

    public function deleteAudience(string $id): void
    {
        $this->confirmDel = null;
        $a = \App\Models\PlatformAudience::find($id);
        if (! $a) return;
        $busy = \App\Models\PlatformCampaign::where('audience_id', $a->id)->whereIn('status', ['scheduled', 'sending'])->count();
        if ($busy) {
            Notification::make()->warning()->title("Can't delete — a scheduled campaign uses it")
                ->body('Cancel that campaign or give it another audience first.')->send();
            return;
        }
        // drafts lose the audience and need a new one before they can go
        \App\Models\PlatformCampaign::where('audience_id', $a->id)->where('status', 'draft')->update(['audience_id' => null]);
        if ($this->aEditing === $a->id) $this->closeAudience();
        if ($this->campaignId && $this->cAudience === (string) $a->id) $this->cAudience = '';
        $a->delete();
        Notification::make()->success()->title('Audience deleted')->send();
    }

    public function deleteCampaign(string $id): void
    {
        $this->confirmDel = null;
        $c = \App\Models\PlatformCampaign::find($id);
        if (! $c) return;
        if ($c->status !== 'draft') {
            Notification::make()->warning()->title($c->status === 'sent' ? "Sent campaigns are kept" : 'Cancel it first')
                ->body($c->status === 'sent' ? 'It holds the record of who got what.' : 'Only drafts can be deleted.')->send();
            return;
        }
        $went = \App\Models\PlatformCampaignSend::where('campaign_id', $c->id)->whereNotIn('status', ['pending', 'skipped'])->count();
        if ($went) {
            Notification::make()->warning()->title("Can't delete — some of it already went out")
                ->body("$went emails were sent before it was cancelled; the campaign keeps that record.")->send();
            return;
        }
        \App\Models\PlatformCampaignSend::where('campaign_id', $c->id)->delete();
        if ($this->campaignId === $c->id) $this->campaignId = null;
        $c->delete();
        Notification::make()->success()->title('Campaign deleted')->send();
    }

    public function newCampaign(): void
    {
        $c = \App\Models\PlatformCampaign::create([
            'name'   => 'Untitled campaign',
            'status' => 'draft',
        ]);

        $this->openCampaign($c->id);
    }

    public function openCampaign(string $id): void
    {
        $c = \App\Models\PlatformCampaign::find($id);
        if (! $c) {
            return;
        }

        $this->campaignId = $c->id;
        $this->cName      = (string) $c->name;
        $this->cSubject   = (string) $c->subject;
        $this->cBody      = (string) $c->body;
        $this->cAudience  = (string) $c->audience_id;
        $this->cSchedule  = $c->scheduled_at?->format('Y-m-d\TH:i') ?? '';
        $this->cBlocks    = \App\Support\PlatformLetter::isLetter($c->blocks ?? []) ? array_values($c->blocks) : [];
        $this->cMode      = $this->cBlocks ? 'letter' : 'body';
        $this->shotUploads = [];
        $this->tab        = 'campaigns';
    }

    public function closeCampaign(): void
    {
        $this->campaignId = null;
    }

    public function saveCampaign(): void
    {
        $c = $this->campaignId ? \App\Models\PlatformCampaign::find($this->campaignId) : null;
        if (! $c) {
            return;
        }

        if (! $c->isEditable()) {
            Notification::make()->warning()
                ->title('Already sending')
                ->body('A campaign stops being editable once it starts going out.')
                ->send();
            return;
        }

        // uploaded screenshots land in public storage and
        // the block keeps an absolute URL, since a mail client has no site to be
        // relative to.
        foreach ($this->shotUploads as $i => $file) {
            if ($file && isset($this->cBlocks[$i]) && ($this->cBlocks[$i]['type'] ?? '') === 'letter_shot') {
                $path = $file->store('platform-letters', 'public');
                $this->cBlocks[$i]['data']['url'] = url('/storage/' . $path);
            }
        }
        $this->shotUploads = [];
        $letter = $this->cMode === 'letter';

        $c->update([
            'name'        => trim($this->cName) ?: 'Untitled campaign',
            'subject'     => trim($this->cSubject) ?: null,
            'body'        => $letter ? '' : $this->cBody,
            'blocks'      => $letter ? array_values($this->cBlocks) : null,
            'audience_id' => $this->cAudience ?: null,
        ]);

        Notification::make()->success()->title('Saved')->send();
    }

    /** Schedule, or send now — both go through the worker, never inline. */
    public function scheduleCampaign(): void
    {
        $this->saveCampaign();

        $c = \App\Models\PlatformCampaign::find($this->campaignId);
        if (! $c) {
            return;
        }

        $reach = null;
        if ($c->audience) {
            $svc   = app(\App\Services\Platform\PlatformAudienceService::class);
            $reach = ['matched' => 0, 'mailable' => $svc->mailable($c->audience)->count()];
        }

        $blockers = $this->blockers($c, $reach);
        if ($blockers) {
            Notification::make()->warning()->title('Not ready to send')->body(implode(' ', $blockers))->send();
            return;
        }

        // An empty schedule means now. The worker still does the sending, so
        // one code path builds the list and re-checks opt-outs either way.
        $when = trim($this->cSchedule) !== '' ? \Carbon\Carbon::parse($this->cSchedule) : now();

        $c->update(['status' => 'scheduled', 'scheduled_at' => $when]);

        Notification::make()->success()
            ->title($when->isFuture() ? 'Scheduled for ' . $when->format('D M j, g:i A') : 'Sending now')
            ->body('The worker builds the recipient list when it fires, so anyone who unsubscribes before then is excluded.')
            ->send();
    }

    // ------------------------------------------------------

    public function useLetter(): void
    {
        $this->cMode = 'letter';
        if (! $this->cBlocks) {
            $this->cBlocks = \App\Support\PlatformLetter::starter();
            if (trim($this->cBody) !== '') {
                $this->cBlocks[0]['data']['text'] = $this->cBody; // keep what was written
            }
        }
    }

    public function useBody(): void
    {
        $this->cMode = 'body';
    }

    public function addLetterBlock(string $type): void
    {
        if (isset(\App\Support\PlatformLetter::TYPES[$type])) {
            $this->cBlocks[] = \App\Support\PlatformLetter::blank($type);
        }
    }

    public function removeLetterBlock(int $i): void
    {
        unset($this->cBlocks[$i], $this->shotUploads[$i]);
        $this->cBlocks = array_values($this->cBlocks);
        $this->shotUploads = [];
    }

    public function moveLetterBlock(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (isset($this->cBlocks[$i], $this->cBlocks[$j])) {
            [$this->cBlocks[$i], $this->cBlocks[$j]] = [$this->cBlocks[$j], $this->cBlocks[$i]];
            $this->shotUploads = [];
        }
    }

    public function addNumbersRow(int $i): void
    {
        $this->cBlocks[$i]['data']['rows'][] = ['', '', '', ''];
    }

    public function removeNumbersRow(int $i, int $r): void
    {
        unset($this->cBlocks[$i]['data']['rows'][$r]);
        $this->cBlocks[$i]['data']['rows'] = array_values($this->cBlocks[$i]['data']['rows'] ?? []);
    }

    public function setLetterTheme(string $t): void
    {
        $this->cTheme = in_array($t, ['light', 'dark', 'phone'], true) ? $t : 'light';
    }

    public function cancelCampaign(): void
    {
        $c = $this->campaignId ? \App\Models\PlatformCampaign::find($this->campaignId) : null;
        if (! $c || ! in_array($c->status, ['scheduled', 'sending'], true)) {
            return;
        }

        $c->update(['status' => 'draft', 'scheduled_at' => null]);
        \App\Models\PlatformCampaignSend::where('campaign_id', $c->id)
            ->where('status', 'pending')
            ->update(['status' => 'skipped', 'skip_reason' => 'campaign cancelled']);

        Notification::make()->success()->title('Cancelled')
            ->body('Anything already sent has gone; nothing further will.')->send();
    }
}
