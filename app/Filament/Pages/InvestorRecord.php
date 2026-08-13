<?php

namespace App\Filament\Pages;

use App\Models\Investor;
use App\Models\InvestorDocument;
use App\Models\InvestorEvent;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

// MARKER-RAISE-RECORDS
class InvestorRecord extends Page
{
    use WithFileUploads;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Investor';
    protected static string $view   = 'filament.pages.investor-record';

    public ?int $investorId = null;

    public string $name   = '';
    public string $email  = '';
    public string $entity = '';
    public $amount        = 0;
    public $amountReceived = 0;
    public string $fundingMethod = '';
    public string $notes  = '';

    public string $docLabel = '';
    public $upload = null;

    public function mount(): void
    {
        $this->investorId = (int) request()->query('investor');
        $investor = $this->investor();

        $this->name           = $investor->name;
        $this->email          = (string) $investor->email;
        $this->entity         = (string) $investor->entity;
        $this->amount         = $investor->amount;
        $this->amountReceived = $investor->amount_received;
        $this->fundingMethod  = (string) $investor->funding_method;
        $this->notes          = (string) $investor->notes;
    }

    protected function investor(): Investor
    {
        return Investor::findOrFail($this->investorId);
    }

    public function save(): void
    {
        $data = $this->validate([
            'name'           => ['required', 'string', 'max:190'],
            'email'          => ['nullable', 'email', 'max:190'],
            'entity'         => ['nullable', 'string', 'max:190'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'amountReceived' => ['required', 'numeric', 'min:0'],
            'fundingMethod'  => ['nullable', 'string', 'max:60'],
            'notes'          => ['nullable', 'string', 'max:5000'],
        ]);

        $investor = $this->investor();
        $investor->forceFill([
            'name'            => $data['name'],
            'email'           => $data['email'] ?: null,
            'entity'          => $data['entity'] ?: null,
            'amount'          => (int) $data['amount'],
            'amount_received' => (int) $data['amountReceived'],
            'funding_method'  => $data['fundingMethod'] ?: null,
            'notes'           => $data['notes'] ?: null,
        ])->save();

        InvestorEvent::log($investor->id, 'updated', 'Record edited in master admin');

        Notification::make()->title('Saved')->success()->send();
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'docLabel' => ['required', 'string', 'max:120'],
            'upload'   => ['required', 'file', 'max:20480'],
        ]);

        $investor = $this->investor();
        $path = $this->upload->store('investors/' . $investor->id, 'local');

        InvestorDocument::create([
            'investor_id'   => $investor->id,
            'label'         => $this->docLabel,
            'path'          => $path,
            'original_name' => $this->upload->getClientOriginalName(),
            'mime'          => $this->upload->getMimeType(),
            'size'          => $this->upload->getSize(),
        ]);

        InvestorEvent::log($investor->id, 'document', 'Document added: ' . $this->docLabel);

        // MARKER-RAISE-PORTAL — the document is now visible on their page, so tell them
        \App\Services\InvestorMessenger::send('document_ready', $investor);

        $this->reset(['docLabel', 'upload']);

        Notification::make()->title('Document stored')->success()->send();
    }

    public function toggleVisibility(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        $doc->forceFill(['visible_to_investor' => ! $doc->visible_to_investor])->save();

        Notification::make()->title($doc->visible_to_investor ? 'Visible in portal' : 'Hidden from portal')->send();
    }

    public function markDocumentSigned(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        $doc->forceFill(['signed_at' => now()])->save();

        $investor = $this->investor();
        $investor->forceFill(['signed_at' => $investor->signed_at ?: now()])->save();

        InvestorEvent::log($investor->id, 'signed', 'Countersigned: ' . $doc->label);

        Notification::make()->title('Marked signed')->success()->send();
    }

    public function downloadDocument(int $documentId)
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    public function deleteDocument(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        InvestorEvent::log($this->investorId, 'document', 'Document removed');

        Notification::make()->title('Document removed')->send();
    }

    // MARKER-RAISE-MESSAGES
    public string $previewKey = '';

    public function previewMessage(string $key): void
    {
        $this->previewKey = $this->previewKey === $key ? '' : $key;
    }

    public function sendMessage(string $key): void
    {
        $investor = $this->investor();

        if ($key === 'invitation' && ! $investor->invited_at) {
            $investor->forceFill(['invited_at' => now()])->save();
        }

        $sent = \App\Services\InvestorMessenger::send($key, $investor);

        $this->previewKey = '';

        if ($sent) {
            Notification::make()->title('Sent to ' . $investor->email)->success()->send();
        } else {
            Notification::make()->title('Not sent')->body('No email on file, or the mailer refused it. Check the activity log.')->danger()->send();
        }
    }

    public function getViewData(): array
    {
        $investor = $this->investor();

        return [
            'investor' => $investor,
            'documents' => $investor->documents()->get(),
            'events'    => $investor->events()->limit(50)->get(),
            'cap'       => Investor::CAP,
            'templates' => \App\Services\InvestorMessenger::templates(),
            'preview'   => $this->previewKey
                ? \App\Services\InvestorMessenger::render($this->previewKey, $investor)
                : null,
        ];
    }
}
