<?php

namespace App\Filament\Pages;

use App\Models\SalesEmailTemplate;
use App\Services\Sales\ProspectOutreach;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-EMAIL-OUTREACH: the saved messages Email outreach and the Prospects
 * card pick from. List on the left, editor on the right, explicit Save.
 */
class SalesEmailTemplates extends Page
{
    use \App\Support\UsesAdminNav;
    use \Livewire\WithFileUploads; // MARKER-OUTREACH-TEST-SIG: signature logo upload
    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Email templates';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 11;
    protected static string  $view            = 'filament.pages.sales-email-templates';
    protected static ?string $slug            = 'sales-email-templates';
    protected static ?string $title           = 'Email templates';

    public ?string $editId = null;   // null = nothing open, 'new' = unsaved
    public string $name = '';
    public string $subject = '';
    public string $body = '';
    public string $landing = '/';
    public bool $confirmDelete = false;

    // MARKER-OUTREACH-TEST-SIG: your signature (editId === 'signature')
    public array $sig = [];
    public $logoUpload = null;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $first = SalesEmailTemplate::orderBy('sort')->orderBy('name')->value('id');
        $first ? $this->openTemplate((string) $first) : $this->startNew();
    }

    public function templates()
    {
        return SalesEmailTemplate::orderBy('sort')->orderBy('name')->get();
    }

    /** [template id => ['sent' => n, 'visited' => n]] */
    public function stats(): array
    {
        $sent = DB::table('sales_outreach_sends')->whereNotNull('template_id')
            ->selectRaw('template_id, COUNT(*) n')->groupBy('template_id')->pluck('n', 'template_id');
        $visited = DB::table('sales_outreach_sends as s')
            ->join('sales_prospects as p', 'p.id', '=', 's.sales_prospect_id')
            ->whereNotNull('s.template_id')->whereNotNull('p.link_clicked_at')
            ->whereColumn('p.link_clicked_at', '>=', 's.created_at')
            ->selectRaw('s.template_id, COUNT(DISTINCT s.sales_prospect_id) n')->groupBy('s.template_id')->pluck('n', 'template_id');
        $out = [];
        foreach ($sent as $id => $n) $out[$id] = ['sent' => (int) $n, 'visited' => (int) ($visited[$id] ?? 0)];
        return $out;
    }

    public function landingPages(): array
    {
        return ProspectOutreach::landingPages();
    }

    public function openTemplate(string $id): void
    {
        $t = SalesEmailTemplate::find($id);
        if (! $t) return;
        $this->editId  = (string) $t->id;
        $this->name    = (string) $t->name;
        $this->subject = (string) $t->subject;
        $this->body    = (string) $t->body;
        $this->landing = ProspectOutreach::normalizePage($t->page);
        $this->confirmDelete = false;
        $this->resetErrorBag();
    }

    public function startNew(): void
    {
        $this->editId  = 'new';
        $this->name    = '';
        $this->subject = '';
        $this->body    = "Hi [first_name],\n\n\n\n[link]\n\n";
        $this->landing = '/';
        $this->confirmDelete = false;
        $this->resetErrorBag();
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'name'    => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:191'],
            'body'    => ['required', 'string', 'max:10000'],
        ], ['name.required' => 'Name it.', 'subject.required' => 'Add a subject.', 'body.required' => 'Write the message.']);

        $data = [
            'name'    => trim($this->name),
            'subject' => trim($this->subject),
            'body'    => rtrim($this->body),
            'page'    => ProspectOutreach::normalizePage($this->landing),
        ];
        if ($this->editId && $this->editId !== 'new' && ($t = SalesEmailTemplate::find($this->editId))) {
            $t->update($data);
        } else {
            $t = SalesEmailTemplate::create($data + ['sort' => (int) SalesEmailTemplate::max('sort') + 1]);
        }
        $this->editId = (string) $t->id;
        Notification::make()->title('Template saved')->success()->send();
    }

    /** Send the template, filled with a made-up shop, only to you. Unsaved edits included. */
    public function sendTest(): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:191'],
            'body'    => ['required', 'string', 'max:10000'],
        ], ['subject.required' => 'Add a subject.', 'body.required' => 'Write the message.']);
        [$ok, $msg] = ProspectOutreach::sendTest(ProspectOutreach::sampleProspect(), $this->subject, $this->body, $this->landing);
        Notification::make()->title($msg)->{$ok ? 'success' : 'warning'}()->send();
    }

    public function openSignature(): void
    {
        $this->editId = 'signature';
        $this->sig = ProspectOutreach::signature();
        $this->logoUpload = null;
        $this->confirmDelete = false;
        $this->resetErrorBag();
    }

    public function saveSignature(): void
    {
        $this->validate([
            'sig.name'  => ['nullable', 'string', 'max:80'],
            'sig.title' => ['nullable', 'string', 'max:80'],
            'sig.phone' => ['nullable', 'string', 'max:40'],
            'sig.line'  => ['nullable', 'string', 'max:120'],
            'sig.website' => ['nullable', 'string', 'max:191'], 'sig.instagram' => ['nullable', 'string', 'max:191'],
            'sig.facebook' => ['nullable', 'string', 'max:191'], 'sig.linkedin' => ['nullable', 'string', 'max:191'],
            'logoUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
        ], ['logoUpload.mimes' => 'Use a PNG or JPG; many inboxes don\'t show SVG.', 'logoUpload.max' => 'Keep the logo under 1 MB.']);

        $sig = ProspectOutreach::signature();
        foreach (['name', 'title', 'phone', 'line', 'website', 'instagram', 'facebook', 'linkedin'] as $k) {
            $sig[$k] = trim((string) ($this->sig[$k] ?? ''));
        }
        $sig['enabled'] = (bool) ($this->sig['enabled'] ?? false);
        $sig['logo'] = in_array($this->sig['logo'] ?? 'brand', ['brand', 'upload', 'none'], true) ? $this->sig['logo'] : 'brand';
        if ($this->logoUpload) {
            $sig['logo_path'] = $this->logoUpload->store('outreach', 'public');
            $sig['logo'] = 'upload';
            $this->logoUpload = null;
        }
        if ($sig['logo'] === 'upload' && ($sig['logo_path'] ?? '') === '') $sig['logo'] = 'brand';
        ProspectOutreach::saveSignature($sig);
        $this->sig = ProspectOutreach::signature();
        Notification::make()->title('Signature saved')->success()->send();
    }

    /** The signature as it will look, from the fields as they are now (unsaved). */
    public function signaturePreview(): string
    {
        $sig = array_merge(ProspectOutreach::signature(), $this->sig, ['enabled' => true]);
        if ($this->logoUpload) $sig['logo'] = 'none'; // shown separately until saved
        return ProspectOutreach::signatureHtml($sig);
    }

    public function deleteTemplate(): void
    {
        if ($this->editId && $this->editId !== 'new') {
            SalesEmailTemplate::whereKey($this->editId)->delete();
            Notification::make()->title('Template deleted')->success()->send();
        }
        $first = SalesEmailTemplate::orderBy('sort')->orderBy('name')->value('id');
        $first ? $this->openTemplate((string) $first) : $this->startNew();
    }
}
