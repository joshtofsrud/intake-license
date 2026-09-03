<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

class PasswordEditor extends Page implements HasForms
{
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'tenants';

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?string $navigationLabel = 'Password Editor';
    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.password-editor';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'tenant_id'    => null,
            'new_password' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Set a tenant owner password')
                    ->description('Pick a tenant, type a new password, and set it. The change applies immediately to that tenant\'s owner login.')
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Tenant')
                            ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live(),

                        Placeholder::make('owner')
                            ->label('Owner')
                            ->content(function (callable $get) {
                                $tid = $get('tenant_id');
                                if (! $tid) {
                                    return 'Select a tenant to see its owner.';
                                }
                                $owner = TenantUser::query()
                                    ->where('tenant_id', $tid)
                                    ->where('role', 'owner')
                                    ->where('is_active', true)
                                    ->first();

                                return $owner
                                    ? $owner->name . ' — ' . $owner->email
                                    : 'No active owner on this tenant.';
                            }),

                        TextInput::make('new_password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(10)
                            ->autocomplete('new-password')
                            ->helperText('At least 10 characters. Share it with the owner directly.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $owner = TenantUser::query()
            ->where('tenant_id', $state['tenant_id'])
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            Notification::make()
                ->danger()
                ->title('No active owner found for that tenant.')
                ->body('Nothing was changed.')
                ->send();
            return;
        }

        $owner->update(['password' => Hash::make($state['new_password'])]);

        debug_log()->audit(
            'tenant_password_reset',
            'Master admin set the owner password for ' . $owner->email,
            $owner,
            ['tenant_id' => $owner->tenant_id, 'owner_email' => $owner->email],
        );

        Notification::make()
            ->success()
            ->title('Password updated for ' . $owner->name)
            ->body('It is active now. Share it with them directly.')
            ->persistent()
            ->send();

        $this->form->fill([
            'tenant_id'    => $state['tenant_id'],
            'new_password' => '',
        ]);
    }
}
