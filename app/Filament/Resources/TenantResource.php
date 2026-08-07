<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Tenants';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('subdomain')->required()->maxLength(63),
                Forms\Components\Select::make('plan_tier')
                    ->options([
                        'starter' => 'Starter',
                        'branded' => 'Branded',
                        'scale' => 'Scale',
                        'custom' => 'Custom (enterprise)',
                    ])
                    ->required(),
                Forms\Components\Select::make('onboarding_status')
                    ->options(['pending' => 'Pending', 'complete' => 'Complete', 'suspended' => 'Suspended'])
                    ->required(),
            ])->columns(2),

            // MARKER-PATCH-169 — Direct Payments bridge feature.
            // Single toggle that exposes the tenant-side keys panel. Tenant
            // pastes their own Stripe keys; nothing in this resource handles
            // the keys themselves.
            Forms\Components\Section::make('Direct card payments (beta)')
                ->description('When ON, this tenant can paste their own Stripe keys in their Settings -> Payments tab and run register card-sales directly through their Stripe account. Bridge feature ahead of the full Connect integration.')
                ->schema([
                    Forms\Components\Toggle::make('direct_payments_enabled')
                        ->label('Enable direct card payments for this tenant')
                        ->helperText('Off by default. Flip on for beta testers and yourself.'),
                ])
                ->collapsed(),

            Forms\Components\Section::make('Owner account')->schema([
                Forms\Components\TextInput::make('owner_name')
                    ->label('Owner name')
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($state, $record, $set) {
                        if ($record) {
                            $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $record->id)
                                ->where('role', 'owner')->first();
                            $set('owner_name', $owner?->name);
                            $set('owner_email', $owner?->email);
                            $set('owner_phone', $owner?->phone);
                        }
                    }),
                Forms\Components\TextInput::make('owner_email')
                    ->label('Owner email')
                    ->email()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('owner_phone')
                    ->label('Owner phone')
                    ->tel()
                    ->dehydrated(false),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Tenant $t) => $t->subdomain . '.intake.works'),

                Tables\Columns\TextColumn::make('owner_email')
                    ->label('Owner')
                    ->getStateUsing(function (Tenant $t) {
                        $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                            ->where('role', 'owner')->first();
                        return $owner?->email;
                    })
                    ->description(function (Tenant $t) {
                        $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                            ->where('role', 'owner')->first();
                        return $owner?->phone;
                    })
                    ->searchable(false),

                Tables\Columns\BadgeColumn::make('plan_tier')
                    ->colors([
                        'gray'    => 'starter',
                        'success' => 'branded',
                        'primary' => 'scale',
                        'warning' => 'custom',
                    ]),

                Tables\Columns\BadgeColumn::make('onboarding_status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'complete',
                        'danger'  => 'suspended',
                    ]),

                Tables\Columns\TextColumn::make('appointments_count')
                    ->label('Appts (30d)')
                    ->getStateUsing(function (Tenant $t) {
                        return TenantAppointment::where('tenant_id', $t->id)
                            ->where('created_at', '>=', now()->subDays(30))
                            ->count();
                    }),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('Last activity')
                    ->getStateUsing(function (Tenant $t) {
                        $latest = TenantAppointment::where('tenant_id', $t->id)
                            ->max('created_at');
                        return $latest ? \Carbon\Carbon::parse($latest)->diffForHumans() : 'Never';
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plan_tier')
                    ->options([
                        'starter' => 'Starter',
                        'branded' => 'Branded',
                        'scale' => 'Scale',
                        'custom' => 'Custom',
                    ]),
                Tables\Filters\SelectFilter::make('onboarding_status')
                    ->options(['pending' => 'Pending', 'complete' => 'Complete', 'suspended' => 'Suspended']),
            ])
            ->actions([
                Tables\Actions\Action::make('view_site')
                    ->label('View site')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Tenant $t) => 'https://' . $t->subdomain . '.' . config('intake.domain'))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('impersonate')
                    ->label('Impersonate')
                    ->icon('heroicon-o-user')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(fn (Tenant $t) => redirect()->route('admin.impersonate', $t->id)),

                Tables\Actions\Action::make('reset_password')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->modalHeading('Reset owner password')
                    ->modalDescription('Sets a new password for this tenant\'s owner. Share it with them directly — it is shown only once.')
                    ->modalSubmitActionLabel('Set password')
                    ->fillForm(fn (Tenant $t) => [
                        'new_password' => \Illuminate\Support\Str::random(14),
                    ])
                    ->form([
                        Forms\Components\Placeholder::make('owner_target')
                            ->label('Owner')
                            ->content(function (Tenant $t) {
                                $o = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                                    ->where('role', 'owner')->where('is_active', true)->first();
                                return $o ? ($o->name . ' — ' . $o->email) : 'No active owner on this tenant.';
                            }),
                        Forms\Components\TextInput::make('new_password')
                            ->label('New password')
                            ->required()
                            ->minLength(10)
                            ->helperText('Auto-generated. Edit if you like, then Set password. Copy it before closing.'),
                    ])
                    ->action(function (Tenant $t, array $data) {
                        $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $t->id)
                            ->where('role', 'owner')->where('is_active', true)->first();

                        if (! $owner) {
                            \Filament\Notifications\Notification::make()
                                ->title('No active owner found for this tenant.')
                                ->danger()->send();
                            return;
                        }

                        $owner->update([
                            'password' => \Illuminate\Support\Facades\Hash::make($data['new_password']),
                        ]);

                        debug_log()->audit(
                            'tenant_password_reset',
                            'Master admin reset the owner password for ' . $t->name,
                            $owner,
                            ['tenant_id' => $t->id, 'owner_email' => $owner->email],
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Password updated for ' . $owner->name)
                            ->body('New password: ' . $data['new_password'] . "\nCopy it now — it will not be shown again.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label(fn (Tenant $t) => $t->onboarding_status === 'suspended' ? 'Unsuspend' : 'Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Tenant $t) {
                        $t->update([
                            'onboarding_status' => $t->onboarding_status === 'suspended'
                                ? 'complete'
                                : 'suspended',
                        ]);
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            TenantResource\RelationManagers\FeaturesRelationManager::class,
        ];
    }

    // MARKER-PATCH-142 — wire up Create route for the gift-tenant flow.
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
