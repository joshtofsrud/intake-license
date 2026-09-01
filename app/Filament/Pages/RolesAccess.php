<?php
// MARKER-ADMIN-NAV-GATE — the matrix as a reference page, readable by every
// staff role so anyone can see exactly what their role covers.

namespace App\Filament\Pages;

use App\Support\AdminAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class RolesAccess extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Roles & access';
    protected static ?string $navigationGroup = 'Team';
    protected static ?string $title           = 'Roles & access';
    protected static ?string $slug            = 'roles-access';
    protected static ?int    $navigationSort  = 96;

    protected static string $view = 'filament.pages.roles-access';

    public static function canAccess(): bool
    {
        return in_array(Auth::guard('web')->user()?->roleName(), AdminAccess::STAFF_ROLES, true);
    }

    protected function getViewData(): array
    {
        return [
            'matrix' => AdminAccess::MATRIX,
            'areas'  => [
                'dashboard'     => 'Dashboard',
                'tenants'       => 'Tenants — manage, plans, suspend',
                'impersonation' => 'Impersonation',
                'features'      => 'Feature control',
                'domains'       => 'Domains',
                'catalog'       => 'Distributors & catalog',
                'crm'           => 'Sales CRM — prospects, quotes',
                'reps'          => 'Reps & commissions',
                'marketing'     => 'Marketing site editor',
                'analytics'     => 'Marketing analytics',
                'logs'          => 'Logs & observability',
                'config'        => 'Platform config — Stripe, email, theme',
                'raise'         => 'Raise',
                'team'          => 'Team & roles',
                'scheduling'    => 'Scheduling — calls with prospects', // MARKER-SCHED-ADMIN
            ],
            'myRole' => Auth::guard('web')->user()?->roleName(),
        ];
    }
}
