<?php

namespace App\Filament\Pages;

use App\Models\InvestLead;
use App\Models\Investor;
use App\Models\InvestToken;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

// MARKER-RAISE-ADMIN
class Raise extends Page
{
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'raise';

    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Raise';
    protected static ?string $navigationGroup = 'Raise';
    protected static ?string $title           = 'Raise';
    protected static ?int    $navigationSort  = 90;

    protected static string $view = 'filament.pages.raise';

    // add-investor form
    public string $name   = '';
    public string $email  = '';
    public string $entity = '';
    public $amount        = '';

    // MARKER-RAISE-INVITE — invite form. Separate properties from the
    // commitment form above: the two are different acts and sharing fields
    // would let a half-typed commitment leak into an invitation.
    public string $inviteName    = '';
    public string $inviteEmail   = '';
    public string $inviteMessage = '';
    public string $inviteList    = '';
    public array  $invitePreview = [];
    public ?int   $confirmDeleteId = null;

    public string $wireBank      = '';
    public string $wireAccount   = '';
    public string $wireRouting   = '';
    public string $wireReference = '';
    public string $formDFiledAt  = '';
    public string $blueSkyNotes  = '';

    public function mount(): void
    {
        $this->wireBank      = (string) \App\Models\RaiseSetting::get('wire_bank');
        $this->wireAccount   = (string) \App\Models\RaiseSetting::get('wire_account');
        $this->wireRouting   = (string) \App\Models\RaiseSetting::get('wire_routing');
        $this->wireReference = (string) \App\Models\RaiseSetting::get('wire_reference');
        $this->formDFiledAt  = (string) \App\Models\RaiseSetting::get('form_d_filed_at');
        $this->blueSkyNotes  = (string) \App\Models\RaiseSetting::get('blue_sky_notes');
    }

    public function addInvestor(): void
    {
        $data = $this->validate([
            'name'   => ['required', 'string', 'max:190'],
            'email'  => ['nullable', 'email', 'max:190'],
            'entity' => ['nullable', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $investor = Investor::create([
            'name'         => $data['name'],
            'email'        => $data['email'] ?: null,
            'entity'       => $data['entity'] ?: null,
            'amount'       => (int) $data['amount'],
            'committed_at' => now(),
        ]);

        \App\Models\InvestorEvent::log($investor->id, 'committed', 'Commitment recorded: $' . number_format($investor->amount));
        \App\Services\InvestorMessenger::send('commitment', $investor); // MARKER-RAISE-MESSAGES

        $this->reset(['name', 'email', 'entity', 'amount']);

        Notification::make()->title('Investor added')->success()->send();
    }

    /** MARKER-RAISE-INVITE — one person, one record, one token, one email. */
    public function inviteOne(): void
    {
        $data = $this->validate([
            'inviteName'    => ['required', 'string', 'max:190'],
            'inviteEmail'   => ['required', 'email', 'max:190'],
            'inviteMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        $investor = $this->createInvite($data['inviteName'], $data['inviteEmail'], $data['inviteMessage'] ?? '');

        $this->reset(['inviteName', 'inviteEmail']);

        Notification::make()
            ->title($investor->name . ' invited')
            ->body('Their own link is on the way. Nothing is committed until they say so.')
            ->success()
            ->send();
    }

    /**
     * MARKER-RAISE-INVITE — parse only. Sends nothing, writes nothing.
     *
     * Deliberately a separate step: a pasted list is the easiest place to
     * mail the wrong people, so the parse is shown before anything leaves.
     */
    public function previewList(): void
    {
        $rows = [];
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->inviteList) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }

            $name  = $line;
            $email = '';

            if (preg_match('/^(.*?)[<,;\t]\s*([^<>,;\s]+@[^<>,;\s]+?)>?$/', $line, $m)) {
                $name  = trim($m[1], " \t\"'");
                $email = strtolower(trim($m[2]));
            } elseif (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($line);
                $name  = '';
            }

            $problem = null;
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $problem = 'No usable email address';
            } elseif ($name === '') {
                $problem = 'No name — the message would open impersonally';
            } elseif (isset($seen[$email])) {
                $problem = 'Repeated in this list';
            } elseif (Investor::where('email', $email)->exists()) {
                $problem = 'Already has a record';
            }

            $seen[$email] = true;
            $rows[] = ['line' => $line, 'name' => $name, 'email' => $email, 'problem' => $problem];
        }

        $this->invitePreview = $rows;

        if (! $rows) {
            Notification::make()->title('Nothing to preview')->send();
        }
    }

    /** MARKER-RAISE-INVITE — only ever acts on rows already shown in the preview. */
    public function sendList(): void
    {
        if (! $this->invitePreview) {
            Notification::make()
                ->title('Preview the list first')
                ->body('Nothing sends until you have seen what parsed.')
                ->warning()
                ->send();

            return;
        }

        $sent = 0;
        foreach ($this->invitePreview as $row) {
            if ($row['problem']) { continue; }
            $this->createInvite($row['name'], $row['email'], $this->inviteMessage);
            $sent++;
        }

        $skipped = count($this->invitePreview) - $sent;
        $this->reset(['inviteList', 'invitePreview']);

        Notification::make()
            ->title($sent . ' ' . str($sent)->plural('invitation') . ' sent')
            ->body($skipped ? $skipped . ' row(s) skipped — they are listed above with the reason.' : 'Every row went out.')
            ->success()
            ->send();
    }

    /** MARKER-RAISE-INVITE — shared by both paths so they can't drift apart. */
    private function createInvite(string $name, string $email, string $message): Investor
    {
        $investor = Investor::create([
            'name'       => $name,
            'email'      => $email,
            'invited_at' => now(),
        ]);

        \App\Models\InvestorEvent::log($investor->id, 'invited', 'Invited by email with a personal note');
        \App\Services\InvestorMessenger::send('invitation', $investor, ['message' => $message]);

        return $investor;
    }

    /** MARKER-RAISE-INVITE — an invite nobody answered is not a cap-table line. */
    public function askDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function deleteInvite(int $id): void
    {
        $investor = Investor::findOrFail($id);

        // Anything that has been committed, signed or funded is history and
        // stays. Only a silent invitation can be removed outright.
        if ($investor->committed_at || $investor->signed_at || $investor->funded_at || $investor->amount_received) {
            Notification::make()
                ->title('Not deleted')
                ->body('This record has a commitment against it. Decline it instead — the history stays.')
                ->danger()
                ->send();

            $this->confirmDeleteId = null;

            return;
        }

        $name = $investor->name;
        $investor->delete();
        $this->confirmDeleteId = null;

        Notification::make()->title($name . ' removed')->success()->send();
    }

    public function markSigned(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['signed_at' => now(), 'declined_at' => null])->save();
        \App\Models\InvestorEvent::log($investor->id, 'signed', 'Marked signed');
        \App\Services\InvestorMessenger::send('signed', $investor); // MARKER-RAISE-MESSAGES

        Notification::make()->title($investor->name . ' marked signed')->success()->send();
    }

    public function markFunded(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill([
            'funded_at'       => now(),
            'signed_at'       => $investor->signed_at ?: now(),
            'amount_received' => $investor->amount_received ?: $investor->amount,
            'declined_at'     => null,
        ])->save();

        \App\Models\InvestorEvent::log($investor->id, 'funded', 'Funds received: $' . number_format($investor->amount_received));
        \App\Services\InvestorMessenger::send('funded', $investor); // MARKER-RAISE-MESSAGES

        Notification::make()->title('Funds recorded for ' . $investor->name)->success()->send();
    }

    public function markDeclined(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['declined_at' => now()])->save();
        \App\Models\InvestorEvent::log($investor->id, 'declined', 'Marked declined');

        Notification::make()->title($investor->name . ' marked declined')->send();
    }

    public function reopen(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['declined_at' => null])->save();

        Notification::make()->title($investor->name . ' reopened')->send();
    }

    public function rotateInviteLink(): void
    {
        $token = InvestToken::rotate('rotated from master admin');

        Notification::make()
            ->title('New link issued')
            ->body('Every previously shared link is now dead.')
            ->warning()
            ->send();
    }

    // MARKER-RAISE-RECORDS
    public function saveWireInstructions(): void
    {
        \App\Models\RaiseSetting::put('wire_bank', $this->wireBank ?: null);
        \App\Models\RaiseSetting::put('wire_account', $this->wireAccount ?: null);
        \App\Models\RaiseSetting::put('wire_routing', $this->wireRouting ?: null);
        \App\Models\RaiseSetting::put('wire_reference', $this->wireReference ?: null);
        \App\Models\RaiseSetting::put('form_d_filed_at', $this->formDFiledAt ?: null);
        \App\Models\RaiseSetting::put('blue_sky_notes', $this->blueSkyNotes ?: null);

        Notification::make()->title('Round settings saved')->success()->send();
    }

    public function getViewData(): array
    {
        $investors = Investor::orderByRaw('funded_at is null')
            ->orderByDesc('amount')
            ->get();

        $active = $investors->whereNull('declined_at');

        // MARKER-RAISE-INVITE — invited and silent is not a commitment, and
        // showing the two together makes a pasted list look like a pipeline.
        $invited = $investors->filter(
            fn ($i) => $i->invited_at && ! $i->committed_at && ! $i->declined_at
        );

        return [
            'investors'  => $investors->reject(fn ($i) => $invited->contains('id', $i->id)),
            'invited'    => $invited,
            'committed'  => (int) $active->sum('amount'),
            'received'   => (int) $active->sum('amount_received'),
            'target'     => Investor::target(),
            'cap'        => Investor::cap(),
            'token'      => InvestToken::current(),
            'leads'      => InvestLead::latest()->limit(25)->get(),
            'leadCount'  => InvestLead::count(),
        ];
    }
}
