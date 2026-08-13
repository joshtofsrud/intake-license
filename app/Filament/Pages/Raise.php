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

        return [
            'investors'  => $investors,
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
