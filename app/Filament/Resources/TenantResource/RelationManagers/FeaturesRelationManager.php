<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Models\Tenant;
use App\Services\AddonManagementService;
use App\Services\FeatureAccessService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FeaturesRelationManager
 *
 * Panel on the Tenant detail page listing every addon/feature.
 * Staff can:
 *   - Toggle any feature on (source = staff_push)
 *   - Toggle any feature off (cancel active row OR suppress plan-included)
 *   - See audit context (who did what, when)
 *
 * Register via TenantResource::getRelations().
 */
class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Features';

    protected static ?string $icon = 'heroicon-o-squares-2x2';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Feature')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->description),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->colors([
                        'primary' => 'communication',
                        'success' => 'operations',
                        'warning' => 'onboarding',
                        'gray' => 'feature',
                    ]),

                Tables\Columns\TextColumn::make('price_display')
                    ->label('Price')
                    ->getStateUsing(fn ($record) => $this->priceDisplay($record)),

                Tables\Columns\TextColumn::make('plan_badges')
                    ->label('Included in')
                    ->html()
                    ->getStateUsing(fn ($record) => $this->planBadges($record)),

                Tables\Columns\TextColumn::make('access_state')
                    ->label('Status')
                    ->html()
                    ->getStateUsing(fn ($record) => $this->accessBadge($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'communication' => 'Communication',
                        'operations' => 'Operations',
                        'feature' => 'Tier features',
                        'onboarding' => 'Onboarding',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn ($record) => ! $this->tenantHasAccess($record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (optional)')
                            ->placeholder('e.g. beta comp, customer support case #1234')
                            ->rows(2),
                    ])
                    ->action(function (array $data, $record) {
                        app(AddonManagementService::class)->activate(
                            $this->getOwnerRecord(),
                            $record->code,
                            [
                                'source' => 'staff_push',
                                'actor_type' => 'staff',
                                'actor_id' => Auth::id(),
                                'actor_label' => Auth::user()?->name ?? 'master admin',
                                'reason' => $data['reason'] ?? null,
                            ]
                        );

                        Notification::make()
                            ->success()
                            ->title('Feature activated')
                            ->body("{$record->name} is now active for this tenant.")
                            ->send();
                    }),

                Tables\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $this->tenantHasTenantAddonRow($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Deactivate {$record->name}?")
                    ->modalDescription('Access will end immediately for staff-push rows, or at the end of the current billing period for self-serve rows.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (optional)')
                            ->rows(2),
                    ])
                    ->action(function (array $data, $record) {
                        app(AddonManagementService::class)->cancel(
                            $this->getOwnerRecord(),
                            $record->code,
                            [
                                'actor_type' => 'staff',
                                'actor_id' => Auth::id(),
                                'actor_label' => Auth::user()?->name ?? 'master admin',
                                'reason' => $data['reason'] ?? null,
                            ]
                        );

                        Notification::make()
                            ->success()
                            ->title('Feature deactivated')
                            ->body("{$record->name} has been deactivated.")
                            ->send();
                    }),

                Tables\Actions\Action::make('suppress')
                    ->label('Revoke plan access')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn ($record) => $this->tenantHasPlanAccess($record) && ! $this->tenantIsSuppressed($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Revoke plan-included access to {$record->name}?")
                    ->modalDescription("This tenant's plan includes this feature by default. Revoking here blocks access regardless of plan.")
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (required)')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (array $data, $record) {
                        app(AddonManagementService::class)->suppress(
                            $this->getOwnerRecord(),
                            $record->code,
                            [
                                'actor_id' => Auth::id(),
                                'actor_label' => Auth::user()?->name ?? 'master admin',
                                'reason' => $data['reason'],
                            ]
                        );

                        Notification::make()
                            ->warning()
                            ->title('Plan access revoked')
                            ->body("Access to {$record->name} is now suppressed for this tenant.")
                            ->send();
                    }),

                Tables\Actions\Action::make('lift_suppression')
                    ->label('Restore plan access')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('primary')
                    ->visible(fn ($record) => $this->tenantIsSuppressed($record))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(AddonManagementService::class)->liftSuppression(
                            $this->getOwnerRecord(),
                            $record->code,
                            [
                                'actor_id' => Auth::id(),
                                'actor_label' => Auth::user()?->name ?? 'master admin',
                                'reason' => 'suppression lifted',
                            ]
                        );

                        Notification::make()
                            ->success()
                            ->title('Plan access restored')
                            ->send();
                    }),
            ])
            ->paginated(false)
            ->defaultSort('sort_order');
    }

    protected function buildQuery(): Builder
    {
        return \App\Models\Addon::query()->active()->ordered();
    }

    protected function tenantHasAccess($record): bool
    {
        return app(FeatureAccessService::class)->hasAddon($this->getOwnerRecord(), $record->code);
    }

    protected function tenantHasTenantAddonRow($record): bool
    {
        return DB::table('tenant_feature_addons')
            ->where('tenant_id', $this->getOwnerRecord()->id)
            ->where('addon_code', $record->code)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->exists();
    }

    protected function tenantHasPlanAccess($record): bool
    {
        $plans = is_array($record->included_in_plans) ? $record->included_in_plans : [];
        return in_array($this->getOwnerRecord()->plan_tier ?? 'starter', $plans, true);
    }

    protected function tenantIsSuppressed($record): bool
    {
        return DB::table('tenant_addon_suppressions')
            ->where('tenant_id', $this->getOwnerRecord()->id)
            ->where('addon_code', $record->code)
            ->whereNull('lifted_at')
            ->exists();
    }

    protected function priceDisplay($record): string
    {
        if ($record->price_display_override) {
            return $record->price_display_override;
        }
        if ($record->billing_cadence === 'one_time') {
            return '$' . number_format($record->price_cents / 100, 0) . ' once';
        }
        if ($record->price_cents === 0) {
            return '—';
        }
        return '$' . number_format($record->price_cents / 100, 0) . '/mo';
    }

    protected function planBadges($record): string
    {
        $plans = is_array($record->included_in_plans) ? $record->included_in_plans : [];
        if (empty($plans)) {
            return '<span style="color: var(--gray-500); font-size: 0.85em;">None</span>';
        }

        $currentTier = $this->getOwnerRecord()->plan_tier ?? 'starter';

        $html = [];
        foreach ($plans as $plan) {
            $highlight = $plan === $currentTier;
            $bg = $highlight ? '#BEF264' : '#2a2a2a';
            $fg = $highlight ? '#0a0a0a' : '#bdbdbd';
            $html[] = sprintf(
                '<span style="background:%s;color:%s;padding:2px 8px;border-radius:4px;margin-right:4px;font-size:0.75rem;font-weight:600;text-transform:capitalize;">%s</span>',
                $bg,
                $fg,
                e($plan)
            );
        }

        return implode('', $html);
    }

    protected function accessBadge($record): string
    {
        $tenant = $this->getOwnerRecord();
        $hasAccess = $this->tenantHasAccess($record);
        $suppressed = $this->tenantIsSuppressed($record);

        if ($suppressed) {
            return '<span style="background:#7a2323;color:#fca5a5;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;">SUPPRESSED</span>';
        }

        if (! $hasAccess) {
            return '<span style="color:#6a6a6a;font-size:0.85em;">Not active</span>';
        }

        $tenantAddon = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $record->code)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->first();

        if ($tenantAddon) {
            $sourceLabels = [
                'self_serve' => ['Paid addon', '#1e3a5f', '#93c5fd'],
                'staff_push' => ['Staff comp', '#4a3a1a', '#fbbf24'],
                'beta_comp' => ['Beta comp', '#3a1a4a', '#c4b5fd'],
            ];
            [$label, $bg, $fg] = $sourceLabels[$tenantAddon->source] ?? ['Active', '#1a3a1a', '#86efac'];

            $extra = '';
            if ($tenantAddon->status === 'canceling') {
                $extra = ' <span style="color:#fbbf24;font-size:0.7rem;">(canceling)</span>';
            } elseif ($tenantAddon->status === 'failed_payment') {
                $extra = ' <span style="color:#f87171;font-size:0.7rem;">(payment failed)</span>';
            }

            return sprintf(
                '<span style="background:%s;color:%s;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;">%s</span>%s',
                $bg, $fg, e($label), $extra
            );
        }

        return '<span style="background:#1a3a1a;color:#86efac;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;">Included in plan</span>';
    }
}
