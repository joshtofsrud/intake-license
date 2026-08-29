<?php

namespace App\Filament\Pages;

use App\Models\InvestDocument;
use App\Models\Investor;
use App\Models\RaiseMessageTemplate;
use App\Models\RaiseSetting;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

// MARKER-RAISE-SETUP
class RaiseSetup extends Page
{
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'raise';

    use WithFileUploads;

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Raise setup';
    protected static ?string $navigationGroup = 'Raise';
    protected static ?string $title           = 'Raise setup';
    protected static ?int    $navigationSort  = 91;

    protected static string $view = 'filament.pages.raise-setup';

    // round
    public string $roundName  = '';
    public string $instrument = '';
    public $cap;
    public $target;
    public string $senderName = '';
    public string $roundStatus = 'open';

    // wire + compliance
    public string $wireBank      = '';
    public string $wireAccount   = '';
    public string $wireRouting   = '';
    public string $wireReference = '';
    public string $formDFiledAt  = '';
    public string $blueSkyNotes  = '';

    // MARKER-INVEST-LANDING — public landing copy
    public string $landingHeadline   = '';
    public string $landingLede       = '';
    public string $landingFine       = '';
    public string $notifyEmail       = '';
    public bool   $showProgress     = true;   // MARKER-INVEST-LIVE

    // template editor
    public string $templateKey  = '';
    public string $templateSubject = '';
    public string $templateBody    = '';

    // document upload
    public $upload;
    public string $uploadLabel = '';

    public function mount(): void
    {
        $this->roundName   = (string) RaiseSetting::get('round_name', 'Intake — pre-seed');
        $this->instrument  = (string) RaiseSetting::get('instrument', 'Post-money SAFE');
        $this->cap         = (int) RaiseSetting::get('cap', (string) Investor::CAP);
        $this->target      = (int) RaiseSetting::get('target', (string) Investor::TARGET);
        $this->senderName  = (string) RaiseSetting::get('sender_name', 'Josh');
        $this->roundStatus = (string) RaiseSetting::get('round_status', 'open');

        $this->wireBank      = (string) RaiseSetting::get('wire_bank');
        $this->wireAccount   = (string) RaiseSetting::get('wire_account');
        $this->wireRouting   = (string) RaiseSetting::get('wire_routing');
        $this->wireReference = (string) RaiseSetting::get('wire_reference');
        $this->formDFiledAt  = (string) RaiseSetting::get('form_d_filed_at');
        $this->blueSkyNotes  = (string) RaiseSetting::get('blue_sky_notes');

        // MARKER-INVEST-LANDING — blank means the view's own default is used,
        // so an untouched field is not an empty page.
        $this->landingHeadline   = (string) RaiseSetting::get('landing_headline');
        $this->landingLede       = (string) RaiseSetting::get('landing_lede');
        $this->landingFine       = (string) RaiseSetting::get('landing_fine');
        $this->notifyEmail       = (string) RaiseSetting::get('notify_email');
        $this->showProgress      = RaiseSetting::get('show_progress', '1') === '1';
    }

    /** MARKER-INVEST-LANDING */
    public function saveLanding(): void
    {
        $this->validate([
            'landingHeadline'   => ['nullable', 'string', 'max:200'],
            'landingLede'       => ['nullable', 'string', 'max:1000'],
            'landingFine'       => ['nullable', 'string', 'max:1000'],
            'notifyEmail'       => ['nullable', 'email', 'max:190'],
        ]);

        RaiseSetting::put('landing_headline',    $this->landingHeadline ?: null);
        RaiseSetting::put('landing_lede',        $this->landingLede ?: null);
        RaiseSetting::put('landing_fine',        $this->landingFine ?: null);
        RaiseSetting::put('notify_email',        $this->notifyEmail ?: null);
        RaiseSetting::put('show_progress',       $this->showProgress ? '1' : '0');

        Notification::make()
            ->title('Landing copy saved')
            ->body('Live at /invest immediately.')
            ->success()
            ->send();
    }

    public function saveRound(): void
    {
        $this->validate([
            'roundName'  => ['required', 'string', 'max:120'],
            'instrument' => ['nullable', 'string', 'max:120'],
            'cap'        => ['required', 'numeric', 'min:1'],
            'target'     => ['required', 'numeric', 'min:1'],
            'senderName' => ['nullable', 'string', 'max:120'],
        ]);

        RaiseSetting::put('round_name', $this->roundName);
        RaiseSetting::put('instrument', $this->instrument ?: null);
        RaiseSetting::put('cap', (string) (int) $this->cap);
        RaiseSetting::put('target', (string) (int) $this->target);
        RaiseSetting::put('sender_name', $this->senderName ?: null);
        RaiseSetting::put('round_status', $this->roundStatus);

        Notification::make()
            ->title('Round saved')
            ->body('Every percentage recalculates against the new cap.')
            ->success()
            ->send();
    }

    public function saveCompliance(): void
    {
        RaiseSetting::put('wire_bank', $this->wireBank ?: null);
        RaiseSetting::put('wire_account', $this->wireAccount ?: null);
        RaiseSetting::put('wire_routing', $this->wireRouting ?: null);
        RaiseSetting::put('wire_reference', $this->wireReference ?: null);
        RaiseSetting::put('form_d_filed_at', $this->formDFiledAt ?: null);
        RaiseSetting::put('blue_sky_notes', $this->blueSkyNotes ?: null);

        Notification::make()->title('Wire and compliance details saved')->success()->send();
    }

    public function editTemplate(string $key): void
    {
        $template = RaiseMessageTemplate::merged()[$key] ?? null;

        if (! $template) {
            return;
        }

        $this->templateKey     = $key;
        $this->templateSubject = $template['subject'] ?? '';
        $this->templateBody    = $template['body'] ?? '';
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'templateKey'     => ['required', 'string'],
            'templateSubject' => ['required', 'string', 'max:190'],
            'templateBody'    => ['required', 'string'],
        ]);

        RaiseMessageTemplate::updateOrCreate(
            ['key' => $this->templateKey],
            ['subject' => $this->templateSubject, 'body' => $this->templateBody]
        );

        Notification::make()->title('Template saved')->success()->send();
    }

    public function resetTemplate(): void
    {
        RaiseMessageTemplate::where('key', $this->templateKey)->delete();

        $this->editTemplate($this->templateKey);

        Notification::make()->title('Template reset to the shipped wording')->send();
    }

    public function closeTemplate(): void
    {
        $this->reset(['templateKey', 'templateSubject', 'templateBody']);
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'upload'      => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'uploadLabel' => ['required', 'string', 'max:120'],
        ]);

        $slug = Str::slug($this->uploadLabel) ?: 'document-' . time();

        // Same slug replaces the file rather than piling up copies.
        $existing = InvestDocument::where('slug', $slug)->first();
        $filename = $slug . '.pdf';

        $this->upload->storeAs('invest', $filename);

        InvestDocument::updateOrCreate(
            ['slug' => $slug],
            [
                'label'     => $this->uploadLabel,
                'path'      => 'invest/' . $filename,
                'is_active' => $existing?->is_active ?? true,
            ]
        );

        $this->reset(['upload', 'uploadLabel']);

        Notification::make()->title('Document uploaded')->success()->send();
    }

    public function toggleDocument(int $id): void
    {
        $doc = InvestDocument::findOrFail($id);
        $doc->forceFill(['is_active' => ! $doc->is_active])->save();
    }

    public function deleteDocument(int $id): void
    {
        $doc = InvestDocument::findOrFail($id);

        $path = storage_path('app/' . $doc->path);
        if (is_file($path)) {
            @unlink($path);
        }

        $doc->delete();

        Notification::make()->title('Document removed')->send();
    }

    public function getViewData(): array
    {
        return [
            'templates' => RaiseMessageTemplate::merged(),
            'overrides' => RaiseMessageTemplate::pluck('key')->all(),
            'documents' => InvestDocument::orderBy('sort')->orderBy('id')->get(),
            'committed' => (int) Investor::query()->whereNull('declined_at')->sum('amount'),
        ];
    }
}
