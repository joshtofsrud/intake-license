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

    #[Url(as: 'edit')]
    public ?string $editing = null;

    public string $subject = '';
    public string $body    = '';
    public string $testTo  = '';

    public function mount(): void
    {
        if ($this->editing) {
            $this->loadTemplate($this->editing);
        }
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

        return [
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

            Notification::make()->success()->title('Test sent to ' . $to)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
        }
    }

    /** What the current editor state would actually look like. */
    protected function previewHtml(): string
    {
        $vars = PlatformEmailTemplates::sampleVars((string) $this->editing);

        return view('emails.platform.custom', [
            'bodyHtml' => nl2br(e(PlatformEmailTemplates::merge($this->body, $vars))),
            'postal'   => PlatformMailer::postalAddress(),
        ])->render();
    }
}
