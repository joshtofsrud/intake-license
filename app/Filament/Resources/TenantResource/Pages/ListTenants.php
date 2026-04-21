<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\TenantMetricsService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;

/**
 * Custom list page for Tenant resource.
 *
 * Replaces the default Filament table with a card-grid layout.
 * Filters: lifecycle status, plan tier, subscription status, trial status.
 * Sort: newest / oldest / alphabetical / MRR / last activity.
 * Search: subdomain, name, owner email.
 *
 * The __platform tenant is always visible but protected — no delete,
 * no lifecycle actions. Shown with a "Platform" badge in place of tier.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;
    protected static string $view = 'filament.resources.tenant-resource.pages.list-tenants';

    public ?string $filterStatus = 'all';
    public ?string $filterPlan = 'all';
    public ?string $filterSubscription = 'all';
    public ?string $sort = 'newest';
    public ?string $search = '';

    // Delete modal state
    public ?string $pendingDeleteId = null;
    public ?string $deleteConfirmText = '';

    protected $queryString = [
        'filterStatus' => ['except' => 'all'],
        'filterPlan' => ['except' => 'all'],
        'filterSubscription' => ['except' => 'all'],
        'sort' => ['except' => 'newest'],
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

    protected function getViewData(): array
    {
        $query = Tenant::query();

        // Text search (subdomain, name, owner email)
        if ($this->search) {
            $q = $this->search;
            $query->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('subdomain', 'like', "%{$q}%")
                    ->orWhereExists(function ($e) use ($q) {
                        $e->selectRaw('1')
                            ->from('tenant_users')
                            ->whereColumn('tenant_users.tenant_id', 'tenants.id')
                            ->where('tenant_users.role', 'owner')
                            ->where(function ($em) use ($q) {
                                $em->where('tenant_users.email', 'like', "%{$q}%")
                                    ->orWhere('tenant_users.name', 'like', "%{$q}%");
                            });
                    });
            });
        }

        if ($this->filterPlan !== 'all') {
            $query->where('plan_tier', $this->filterPlan);
        }

        if ($this->filterSubscription !== 'all') {
            if ($this->filterSubscription === 'none') {
                $query->whereNull('subscription_status');
            } else {
                $query->where('subscription_status', $this->filterSubscription);
            }
        }

        // Sort
        switch ($this->sort) {
            case 'oldest':       $query->orderBy('created_at'); break;
            case 'alpha':        $query->orderBy('name'); break;
            case 'alpha_desc':   $query->orderByDesc('name'); break;
            case 'newest':
            default:             $query->orderByDesc('created_at');
        }

        $tenants = $query->get();

        $metrics = app(TenantMetricsService::class);
        $tenantData = $tenants->map(function (Tenant $t) use ($metrics) {
            $m = $metrics->forTenant($t);

            $owner = TenantUser::where('tenant_id', $t->id)
                ->where('role', 'owner')
                ->first();

            $lifecycle = $this->resolveLifecycle($t, $m['is_trial']);
            $isPlatform = $t->subdomain === '__platform';

            return (object) [
                'id' => $t->id,
                'name' => $t->name,
                'subdomain' => $t->subdomain,
                'plan_tier' => $t->plan_tier,
                'onboarding_status' => $t->onboarding_status,
                'subscription_status' => $t->subscription_status,
                'trial_ends_at' => $t->trial_ends_at,
                'created_at' => $t->created_at,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'lifecycle' => $lifecycle,
                'mrr_cents' => $m['mrr_cents'],
                'addon_count' => $m['addon_count'],
                'bookings_30d' => $m['bookings_30d'],
                'initial' => $this->initialFor($t->name),
                'avatar_color' => $this->avatarColorFor($t->name),
                'is_platform' => $isPlatform,
                'is_protected' => $isPlatform, // can expand later (e.g. Intake employees)
            ];
        });

        // MRR-based sort requires computed metrics, so apply after the map
        if ($this->sort === 'mrr_desc') {
            $tenantData = $tenantData->sortByDesc('mrr_cents')->values();
        } elseif ($this->sort === 'mrr_asc') {
            $tenantData = $tenantData->sortBy('mrr_cents')->values();
        }

        // Apply status filter in PHP after lifecycle resolution
        if ($this->filterStatus !== 'all') {
            $tenantData = $tenantData->filter(fn ($t) => $t->lifecycle === $this->filterStatus)->values();
        }

        $counts = [
            'all' => $tenants->count(),
            'active' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, $metrics->forTenant($t)['is_trial']) === 'active')->count(),
            'trial' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, $metrics->forTenant($t)['is_trial']) === 'trial')->count(),
            'suspended' => $tenants->filter(fn ($t) => $this->resolveLifecycle($t, $metrics->forTenant($t)['is_trial']) === 'suspended')->count(),
        ];

        $totalMrr = $tenantData->sum('mrr_cents');

        // Resolve pending delete record (if any) for modal display
        $pendingDelete = $this->pendingDeleteId
            ? $tenantData->firstWhere('id', $this->pendingDeleteId)
            : null;

        return [
            'tenants' => $tenantData,
            'counts' => $counts,
            'totalMrr' => $totalMrr,
            'filterStatus' => $this->filterStatus,
            'filterPlan' => $this->filterPlan,
            'filterSubscription' => $this->filterSubscription,
            'sort' => $this->sort,
            'search' => $this->search,
            'pendingDelete' => $pendingDelete,
            'deleteConfirmText' => $this->deleteConfirmText,
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

    protected function avatarColorFor(string $name): string
    {
        $palette = [
            ['#BEF264', '#1a2a05'], ['#F0997B', '#4A1B0C'],
            ['#B5D4F4', '#042C53'], ['#9FE1CB', '#085041'],
            ['#F4C0D1', '#4B1528'], ['#CECBF6', '#26215C'],
            ['#FAC775', '#412402'], ['#C0DD97', '#173404'],
        ];
        $hash = abs(crc32($name));
        return implode('|', $palette[$hash % count($palette)]);
    }

    // ==================================================================
    // Delete flow
    // ==================================================================

    /**
     * Open the delete confirmation modal for a tenant.
     */
    public function askDelete(string $tenantId): void
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return;
        if ($tenant->subdomain === '__platform') {
            Notification::make()
                ->danger()
                ->title('Cannot delete platform tenant')
                ->send();
            return;
        }

        $this->pendingDeleteId = $tenantId;
        $this->deleteConfirmText = '';
    }

    /**
     * Close the delete modal without action.
     */
    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
        $this->deleteConfirmText = '';
    }

    /**
     * Execute the soft delete. Requires deleteConfirmText to match subdomain.
     */
    public function confirmDelete(): void
    {
        if (! $this->pendingDeleteId) return;

        $tenant = Tenant::find($this->pendingDeleteId);
        if (! $tenant) {
            $this->cancelDelete();
            return;
        }

        if ($tenant->subdomain === '__platform') {
            Notification::make()->danger()->title('Cannot delete platform tenant')->send();
            $this->cancelDelete();
            return;
        }

        if ($this->deleteConfirmText !== $tenant->subdomain) {
            Notification::make()
                ->warning()
                ->title('Confirmation did not match')
                ->body('Please type the subdomain exactly to confirm deletion.')
                ->send();
            return;
        }

        $tenant->delete(); // soft delete (sets deleted_at)

        Log::info('Tenant soft-deleted via master admin', [
            'tenant_id' => $tenant->id,
            'subdomain' => $tenant->subdomain,
            'deleted_by' => auth()->id(),
        ]);

        Notification::make()
            ->success()
            ->title("Deleted {$tenant->subdomain}")
            ->body('Tenant soft-deleted. Data preserved for recovery.')
            ->send();

        $this->cancelDelete();
    }

    // Livewire hooks: reset to page 1 when filters change
    public function updatedFilterStatus(): void { $this->resetPage(); }
    public function updatedFilterPlan(): void { $this->resetPage(); }
    public function updatedFilterSubscription(): void { $this->resetPage(); }
    public function updatedSort(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
}
