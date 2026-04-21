<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\TenantMetricsService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * Custom list page for Tenant resource.
 *
 * Replaces the default Filament table with a card-grid layout.
 * Grouped/filtered by lifecycle status (all/active/trial/suspended) +
 * plan tier filter + search. Each card is whole-clickable to edit,
 * with an overflow menu for per-tenant actions.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected static string $view = 'filament.resources.tenant-resource.pages.list-tenants';

    public ?string $filterStatus = 'all';
    public ?string $filterPlan = 'all';
    public ?string $search = '';

    protected $queryString = [
        'filterStatus' => ['except' => 'all'],
        'filterPlan' => ['except' => 'all'],
        'search' => ['except' => ''],
    ];

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New tenant')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Data passed to the Blade view.
     */
    protected function getViewData(): array
    {
        $query = Tenant::query();

        if ($this->search) {
            $q = $this->search;
            $query->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('subdomain', 'like', "%{$q}%");
            });
        }

        if ($this->filterPlan !== 'all') {
            $query->where('plan_tier', $this->filterPlan);
        }

        $tenants = $query->orderByDesc('created_at')->get();

        $metrics = app(TenantMetricsService::class);
        $tenantData = $tenants->map(function (Tenant $t) use ($metrics) {
            $m = $metrics->forTenant($t);

            $owner = TenantUser::where('tenant_id', $t->id)
                ->where('role', 'owner')
                ->first();

            $lifecycle = $this->resolveLifecycle($t, $m['is_trial']);

            return (object) [
                'id' => $t->id,
                'name' => $t->name,
                'subdomain' => $t->subdomain,
                'plan_tier' => $t->plan_tier,
                'onboarding_status' => $t->onboarding_status,
                'created_at' => $t->created_at,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'lifecycle' => $lifecycle,
                'mrr_cents' => $m['mrr_cents'],
                'addon_count' => $m['addon_count'],
                'bookings_30d' => $m['bookings_30d'],
                'initial' => $this->initialFor($t->name),
                'avatar_color' => $this->avatarColorFor($t->name),
            ];
        });

        // Apply status filter in PHP (after lifecycle resolution)
        if ($this->filterStatus !== 'all') {
            $tenantData = $tenantData->filter(fn ($t) => $t->lifecycle === $this->filterStatus)->values();
        }

        $counts = [
            'all' => $tenants->count(),
            'active' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, app(TenantMetricsService::class)->forTenant($t)['is_trial']) === 'active')->count(),
            'trial' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, app(TenantMetricsService::class)->forTenant($t)['is_trial']) === 'trial')->count(),
            'suspended' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, app(TenantMetricsService::class)->forTenant($t)['is_trial']) === 'suspended')->count(),
        ];

        $totalMrr = $tenantData->sum('mrr_cents');

        return [
            'tenants' => $tenantData,
            'counts' => $counts,
            'totalMrr' => $totalMrr,
            'filterStatus' => $this->filterStatus,
            'filterPlan' => $this->filterPlan,
            'search' => $this->search,
            'domain' => config('intake.domain', 'intake.works'),
        ];
    }

    protected function resolveLifecycle(Tenant $t, bool $isTrial): string
    {
        if (($t->onboarding_status ?? null) === 'suspended') return 'suspended';
        if ($isTrial) return 'trial';
        return 'active';
    }

    protected function initialFor(string $name): string
    {
        return mb_strtoupper(mb_substr(trim($name), 0, 1));
    }

    /**
     * Deterministic color picker from the tenant name.
     * Same name -> same color across renders.
     */
    protected function avatarColorFor(string $name): string
    {
        $palette = [
            ['#BEF264', '#1a2a05'], // lime
            ['#F0997B', '#4A1B0C'], // coral
            ['#B5D4F4', '#042C53'], // blue
            ['#9FE1CB', '#085041'], // teal
            ['#F4C0D1', '#4B1528'], // pink
            ['#CECBF6', '#26215C'], // purple
            ['#FAC775', '#412402'], // amber
            ['#C0DD97', '#173404'], // green
        ];
        $hash = abs(crc32($name));
        return implode('|', $palette[$hash % count($palette)]);
    }

    // Livewire hooks: reset to page 1 when filters change
    public function updatedFilterStatus(): void { $this->resetPage(); }
    public function updatedFilterPlan(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
}
