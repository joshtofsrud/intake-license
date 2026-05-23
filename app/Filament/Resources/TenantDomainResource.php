<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantDomainResource\Pages;
use App\Models\Tenant;
use App\Models\Tenant\TenantDomain;
use App\Services\DomainProvisioningService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin Filament resource for custom domains across all tenants.
 *
 * Lives at intake.works/admin/tenant-domains.
 */
class TenantDomainResource extends Resource
{
    protected static ?string $model = TenantDomain::class;
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'Custom Domains';
    protected static ?string $modelLabel = 'custom domain';
    protected static ?string $pluralModelLabel = 'custom domains';
    protected static ?int $navigationSort = 5;

    /**
     * Badge on the nav showing the count of domains needing attention.
     */
    public static function getNavigationBadge(): ?string
    {
        $needsAttention = TenantDomain::where('status', 'error')
            ->where('last_check_at', '<=', now()->subHours(24))
            ->count();
        return $needsAttention > 0 ? (string) $needsAttention : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Domain')->schema([
                Forms\Components\Select::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->helperText('Which tenant owns this domain. Cannot change after creation.'),

                Forms\Components\TextInput::make('hostname')
                    ->label('Hostname')
                    ->required()
                    ->maxLength(253)
                    ->placeholder('shop.example.com')
                    ->disabledOn('edit')
                    ->helperText('No https://, no trailing slash. Cannot change after creation.'),

                Forms\Components\Select::make('role')
                    ->options([
                        'both'    => 'Both (admin + booking)',
                        'admin'   => 'Admin only',
                        'booking' => 'Public booking only',
                    ])
                    ->default('both')
                    ->required(),

                Forms\Components\Select::make('alias_mode')
                    ->label('When this is an alias')
                    ->options([
                        'redirect'     => 'Redirect to primary (recommended)',
                        'serve_direct' => 'Serve directly (no redirect)',
                    ])
                    ->default('redirect')
                    ->required(),

                Forms\Components\Toggle::make('is_primary')
                    ->label('Mark as primary')
                    ->helperText('Demotes other primary domains for this tenant.'),
            ])->columns(2),

            Forms\Components\Section::make('Status (read-only)')
                ->schema([
                    Forms\Components\TextInput::make('status')->disabled(),
                    Forms\Components\TextInput::make('cloudflare_hostname_id')
                        ->label('Cloudflare hostname ID')
                        ->disabled(),
                    Forms\Components\TextInput::make('last_check_at')
                        ->label('Last check')
                        ->disabled(),
                    Forms\Components\TextInput::make('last_error_code')->disabled(),
                    Forms\Components\Textarea::make('last_error_message')
                        ->disabled()
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2)
                ->hiddenOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->description(function (TenantDomain $d) {
                        return $d->is_primary ? 'primary' : 'alias';
                    }),

                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->description(fn (TenantDomain $d) => $d->tenant?->subdomain),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => ['pending_dns', 'verifying'],
                        'primary' => 'issuing_cert',
                        'success' => 'active',
                        'danger'  => 'error',
                        'gray'    => 'suspended',
                    ]),

                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Age')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_check_at')
                    ->label('Last check')
                    ->since()
                    ->placeholder('never'),

                Tables\Columns\TextColumn::make('last_error_code')
                    ->label('Error')
                    ->placeholder('—')
                    ->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending_dns'  => 'Pending DNS',
                        'verifying'    => 'Verifying',
                        'issuing_cert' => 'Issuing cert',
                        'active'       => 'Active',
                        'error'        => 'Error',
                        'suspended'    => 'Suspended',
                    ]),

                Tables\Filters\Filter::make('needs_attention')
                    ->label('Needs attention (errored >24h)')
                    ->query(fn (Builder $q) => $q->where('status', 'error')
                        ->where('last_check_at', '<=', now()->subHours(24)))
                    ->toggle(),

                Tables\Filters\Filter::make('is_primary')
                    ->label('Primary only')
                    ->query(fn (Builder $q) => $q->where('is_primary', true))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (TenantDomain $record) {
                        try {
                            $svc = app(DomainProvisioningService::class);
                            $svc->syncFromCloudflare($record);
                            Notification::make()
                                ->title('Synced from Cloudflare')
                                ->body($record->fresh()->hostname . ': now ' . $record->fresh()->status)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn (TenantDomain $r) => $r->status !== 'suspended')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->placeholder('e.g. chargeback, abuse complaint'),
                    ])
                    ->action(function (TenantDomain $record, array $data) {
                        app(DomainProvisioningService::class)->suspend($record, $data['reason']);
                        Notification::make()
                            ->title('Suspended')
                            ->body($record->hostname)
                            ->warning()
                            ->send();
                    })
                    ->requiresConfirmation(),

                Tables\Actions\Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (TenantDomain $r) => $r->status === 'suspended')
                    ->action(function (TenantDomain $record) {
                        $record->update([
                            'status' => 'verifying',
                            'suspended_at' => null,
                            'suspended_reason' => null,
                        ]);
                        Notification::make()
                            ->title('Resumed')
                            ->body($record->hostname . ' will be checked on the next poll')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(),

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove custom domain')
                    ->modalDescription('This deletes the domain from Cloudflare AND this database. The tenant will need to re-add it from scratch.')
                    ->using(function (TenantDomain $record) {
                        app(DomainProvisioningService::class)->remove($record);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenantDomains::route('/'),
            'create' => Pages\CreateTenantDomain::route('/create'),
            'edit'   => Pages\EditTenantDomain::route('/{record}/edit'),
        ];
    }
}
