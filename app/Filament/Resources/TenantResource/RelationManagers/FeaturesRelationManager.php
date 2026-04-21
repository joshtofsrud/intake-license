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
use Illuminate\Support\Facades\Auth;

/**
 * FeaturesRelationManager — card-grid version.
 *
 * The table() method is technically unused for rendering (we override the
 * view), but Filament requires a valid Table definition to bootstrap the
 * component. We keep a minimal stub and push all display logic into the
 * custom Blade view at:
 *   resources/views/filament/relations/features-grid.blade.php
 *
 * Actions (activate/deactivate/suppress/lift) are NOT defined as Filament
 * actions here — they're wired as Livewire methods on this class,
 * invokable directly from the Blade via wire:click.
 */
class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Features';

    protected static ?string $icon = 'heroicon-o-squares-2x2';

    protected static string $view = 'filament.relations.features-grid';

    public ?string $filterCategory = 'all';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Required by Filament; we don't actually render the table.
     * The custom view is authoritative.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\Addon::query())
            ->columns([
                Tables\Columns\TextColumn::make('name'),
            ])
            ->paginated(false);
    }

    // ==================================================================
    // View data
    // ==================================================================

    protected function getViewData(): array
    {
        $tenant = $this->getOwnerRecord();
        $features = app(FeatureAccessService::class)->detailedFeatureBreakdown($tenant);

        if ($this->filterCategory !== 'all') {
            $features = $features->filter(fn ($f) => $f->category === $this->filterCategory);
        }

        $grouped = $features->groupBy('category');

        $countsAll = app(FeatureAccessService::class)->detailedFeatureBreakdown($tenant);
        $counts = [
            'all'           => $countsAll->count(),
            'communication' => $countsAll->where('category', 'communication')->count(),
            'operations'    => $countsAll->where('category', 'operations')->count(),
            'feature'       => $countsAll->where('category', 'feature')->count(),
            'onboarding'    => $countsAll->where('category', 'onboarding')->count(),
            'active'        => $countsAll->where('has_access', true)->count(),
            'included'      => $countsAll->where('source', 'plan_tier')->count(),
        ];

        return [
            'tenant' => $tenant,
            'grouped' => $grouped,
            'counts' => $counts,
            'filterCategory' => $this->filterCategory,
        ];
    }

    // ==================================================================
    // Livewire actions — called directly from the Blade via wire:click
    // ==================================================================

    public function activateFeature(string $code, ?string $reason = null): void
    {
        $tenant = $this->getOwnerRecord();

        app(AddonManagementService::class)->activate($tenant, $code, [
            'source' => 'staff_push',
            'actor_type' => 'staff',
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? 'master admin',
            'reason' => $reason,
        ]);

        Notification::make()
            ->success()
            ->title('Feature activated')
            ->send();

        $this->dispatch('$refresh');
    }

    public function deactivateFeature(string $code, ?string $reason = null): void
    {
        $tenant = $this->getOwnerRecord();

        app(AddonManagementService::class)->cancel($tenant, $code, [
            'actor_type' => 'staff',
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? 'master admin',
            'reason' => $reason,
        ]);

        Notification::make()
            ->success()
            ->title('Feature deactivated')
            ->send();

        $this->dispatch('$refresh');
    }

    public function suppressFeature(string $code, string $reason): void
    {
        $tenant = $this->getOwnerRecord();

        app(AddonManagementService::class)->suppress($tenant, $code, [
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? 'master admin',
            'reason' => $reason,
        ]);

        Notification::make()
            ->warning()
            ->title('Plan access revoked')
            ->send();

        $this->dispatch('$refresh');
    }

    public function liftSuppressionFeature(string $code): void
    {
        $tenant = $this->getOwnerRecord();

        app(AddonManagementService::class)->liftSuppression($tenant, $code, [
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? 'master admin',
            'reason' => 'suppression lifted',
        ]);

        Notification::make()
            ->success()
            ->title('Plan access restored')
            ->send();

        $this->dispatch('$refresh');
    }

    // Filter reset on change
    public function updatedFilterCategory(): void {}
}
