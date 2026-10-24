<?php

namespace App\Filament\Pages;

use App\Models\DashboardTemplate;
use App\Models\Tenant\TenantUser;
use App\Support\AdminAccess;
use App\Support\Dashboard\DashboardTemplates;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-DASH-DEFAULT: master admin's view of the shop dashboards.
 *
 * For now it does one thing: choose the default dashboard — the one anyone
 * who hasn't picked a view sees. Phase 2 of the dashboard builder grows this
 * page into the Templates tab.
 */
class Dashboards extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Dashboards';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 40;
    protected static ?string $title = 'Dashboards';
    protected static ?string $slug  = 'dashboards';

    protected static string $view = 'filament.pages.dashboards';

    /** The default as chosen on screen; saved only on Save. */
    public string $default = '';

    public function mount(): void
    {
        $this->default = $this->savedDefault();
    }

    private function savedDefault(): string
    {
        foreach (DashboardTemplates::switchable() as $t) {
            if ($t['default']) return $t['key'];
        }

        return DashboardTemplates::switchable()[0]['key'] ?? '';
    }

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    /** People who chose each template, and people who follow the default. */
    private function counts(): array
    {
        $keys = DashboardTemplates::keys();
        $picked = TenantUser::query()
            ->where('is_active', true)
            ->whereIn('dashboard_view', $keys)
            ->select('dashboard_view', DB::raw('count(*) as n'))
            ->groupBy('dashboard_view')
            ->pluck('n', 'dashboard_view')
            ->all();

        $following = TenantUser::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('dashboard_view')->orWhereNotIn('dashboard_view', $keys))
            ->count();

        $followingShops = TenantUser::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('dashboard_view')->orWhereNotIn('dashboard_view', $keys))
            ->distinct()
            ->count('tenant_id');

        return ['picked' => $picked, 'following' => $following, 'followingShops' => $followingShops];
    }

    public function resetChoice(): void
    {
        $this->default = $this->savedDefault();
    }

    public function save(): void
    {
        if (! $this->canManage()) {
            Notification::make()->title('View access only')->body('Only admins with full Tenants access can change the default.')->danger()->send();
            return;
        }

        $was = $this->savedDefault();
        if (! in_array($this->default, DashboardTemplates::keys(), true)) {
            Notification::make()->title('That dashboard is not available')->danger()->send();
            $this->default = $was;
            return;
        }
        if ($this->default === $was) {
            return;
        }

        DB::transaction(function () {
            DashboardTemplate::query()->update(['is_default' => false]);
            DashboardTemplate::where('key', $this->default)->update(['is_default' => true]);
        });
        DashboardTemplates::flush();

        $t = DashboardTemplate::where('key', $this->default)->first();
        debug_log()->audit('dashboard.default_changed', "Default dashboard: {$was} → {$this->default}", $t);

        Notification::make()
            ->title(($t?->name ?? $this->default) . ' is now the default dashboard')
            ->body('People who haven\'t picked a view see it on their next page load.')
            ->success()->send();
    }

    protected function getViewData(): array
    {
        return [
            'templates'  => DashboardTemplates::switchable(),
            'saved'      => $this->savedDefault(),
            'counts'     => $this->counts(),
            'canManage'  => $this->canManage(),
        ];
    }
}
