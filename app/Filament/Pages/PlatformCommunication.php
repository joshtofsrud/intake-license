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
 * MARKER-PLATFORM-TEMPLATES — one place for every message Intake itself sends.
 *
 * Built in the tenant Communication page's vocabulary deliberately: sender line
 * on top, messages grouped, per-message Edit. Only the tabs that work exist —
 * Inbound, Activity and Suppressions arrive with their own patches.
 */
class PlatformCommunication extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Communication';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int    $navigationSort  = 6;
    protected static string  $view            = 'filament.pages.platform-communication';
    protected static ?string $slug            = 'communication';

    // MARKER-PLATFORM-CAMPAIGNS-UI
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

    public string $aName = '';
    public string $aSource = 'tenants';
    public string $aField = '';
    public string $aOp = 'is';
    public string $aValue = '';

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
        // MARKER-PLATFORM-SENDLOG — four tabs now, all of them real.
        $this->tab = in_array($tab, ['messages', 'campaigns', 'activity', 'suppressions'], true)
            ? $tab
            : 'messages';
    }

    /** MARKER-PLATFORM-SENDLOG — let an address back in. */
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

        // MARKER-PLATFORM-CAMPAIGNS-UI — campaign data, and the reach figure
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

        // MARKER-PLATFORM-MSG-COMPLETE — the senders this page cannot edit,
        // grouped the same way so the list reads as one thing.
        $others = [];
        foreach (PlatformEmailTemplates::OTHER_SENDERS as $row) {
            $others[$row['group']][] = $row;
        }

        // MARKER-PLATFORM-SENDLOG — the log is campaign sends and one-off sends
        // read together, newest first, rather than a second copy of either.
        $activity = collect();
        $suppressions = collect();
        $suppressCounts = ['unsubscribe' => 0, 'bounce' => 0, 'complaint' => 0];

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
            'others'     => $others,
            'audiences'  => $audiences,
            'campaigns'  => $campaigns,
            'campaign'   => $campaign,
            'reach'      => $reach,
            'blockers'   => $campaign ? $this->blockers($campaign, $reach) : [],
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

        // MARKER-PLATFORM-MSG-COMPLETE — empty-body placeholder. Chrome wrapped
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
    // MARKER-PLATFORM-CAMPAIGNS-UI — audiences and campaigns
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
        if (! $c->audience_id) {
            $out[] = 'Pick an audience.';
        }
        if (trim((string) $c->subject) === '') {
            $out[] = 'Write a subject.';
        }
        if (trim((string) $c->body) === '') {
            $out[] = 'Write a body.';
        }
        if ($reach !== null && $reach['mailable'] === 0) {
            $out[] = 'This audience matches nobody who can be mailed right now.';
        }

        return $out;
    }

    public function newAudience(): void
    {
        $name = trim($this->aName);
        if ($name === '') {
            Notification::make()->warning()->title('Name the audience first')->send();
            return;
        }

        $rules = [];
        if (trim($this->aField) !== '' && trim($this->aValue) !== '') {
            $rules[] = ['field' => $this->aField, 'op' => $this->aOp, 'value' => trim($this->aValue)];
        }

        \App\Models\PlatformAudience::create([
            'name'   => $name,
            'source' => $this->aSource,
            'rules'  => $rules,
        ]);

        $this->aName = '';
        $this->aField = '';
        $this->aValue = '';

        Notification::make()->success()->title('Audience saved')->send();
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

        $c->update([
            'name'        => trim($this->cName) ?: 'Untitled campaign',
            'subject'     => trim($this->cSubject) ?: null,
            'body'        => $this->cBody,
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
