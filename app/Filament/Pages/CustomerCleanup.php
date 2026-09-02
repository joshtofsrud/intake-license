<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\CustomerJunkService;
use App\Services\Tenant\CustomerRemovalService;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-CUST-CLEANUP — find the junk in a shop's customer list.
 *
 * Nothing here happens automatically. Removing marketing consent is the default
 * action because it stops the harm (mailing and paying for junk) without
 * touching history; removal is deliberate, uses the shop's own rules, and needs
 * that shop's customer-admin window open.
 */
class CustomerCleanup extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Customer cleanup';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 84;
    protected static string  $view            = 'filament.pages.customer-cleanup';
    protected static ?string $slug            = 'customer-cleanup';

    public ?string $tenantId = null;
    public string  $group    = 'malformed';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->tenantId = request()->query('tenant');
    }

    public function tenants()
    {
        return Tenant::where('is_platform', false)->orderBy('name')->get(['id', 'name', 'subdomain']);
    }

    public function tenant(): ?Tenant
    {
        return $this->tenantId ? Tenant::find($this->tenantId) : null;
    }

    /** Counts for every group, so the shape of the mess is visible at a glance. */
    public function summary(): array
    {
        $tenant = $this->tenant();
        if (! $tenant) return [];

        $svc  = app(CustomerJunkService::class);
        $meta = $svc->meta();
        $out  = [];

        foreach ($svc->groups($tenant) as $key => $query) {
            $out[$key] = [
                'label'     => $meta[$key]['label'] ?? $key,
                'why'       => $meta[$key]['why'] ?? '',
                'confident' => $meta[$key]['confident'] ?? false,
                'count'     => (clone $query)->count(),
            ];
        }

        return $out;
    }

    /** The rows in the selected group, with what removal would do to each. */
    public function rows(): array
    {
        $tenant = $this->tenant();
        if (! $tenant) return [];

        $groups = app(CustomerJunkService::class)->groups($tenant);
        if (! isset($groups[$this->group])) return [];

        $removal = app(CustomerRemovalService::class);

        return $groups[$this->group]
            ->limit(200)
            ->get(['id', 'first_name', 'last_name', 'business_name', 'email', 'phone',
                   'created_at', 'email_marketing_consent_at', 'email_marketing_opt_out_at'])
            ->map(function ($c) use ($removal) {
                $links = $removal->linkCounts($c->id);
                return [
                    'id'       => $c->id,
                    'name'     => trim($c->fullName()) ?: '(no name)',
                    'email'    => $c->email,
                    'phone'    => $c->phone,
                    'added'    => $c->created_at,
                    'mailable' => (bool) ($c->email_marketing_consent_at && ! $c->email_marketing_opt_out_at),
                    'mode'     => array_sum($links) === 0 ? 'delete' : 'erase',
                    'links'    => array_filter($links),
                ];
            })->all();
    }

    public function windowOpen(): bool
    {
        $t = $this->tenant();
        return $t ? $t->customerAdminOpen() : false;
    }

    // ---- actions -----------------------------------------------------

    public function selectGroup(string $key): void
    {
        $this->group = $key;
    }

    /** Non-destructive, and the thing that actually stops junk being mailed. */
    public function optOutGroup(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $groups = app(CustomerJunkService::class)->groups($tenant);
        if (! isset($groups[$this->group])) return;

        $n = 0;
        $groups[$this->group]->whereNotNull('email_marketing_consent_at')
            ->orderBy('id')->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $c) {
                    $c->forceFill([
                        'email_marketing_opt_out_at' => now(),
                    ])->save();
                    $n++;
                }
            });

        logger()->info('MARKER-CUST-CLEANUP bulk opt-out', [
            'tenant_id' => $tenant->id, 'group' => $this->group, 'count' => $n,
            'by' => Auth::guard('web')->id(),
        ]);

        Notification::make()->success()
            ->title($n . ' ' . \Illuminate\Support\Str::plural('customer', $n) . ' opted out of marketing')
            ->body('Their records are untouched — they simply stop receiving campaigns.')
            ->send();
    }

    public function removeOne(string $id): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        if (! $tenant->customerAdminOpen()) {
            Notification::make()->danger()
                ->title('Customer admin mode is closed for this shop')
                ->body('Open a window first — the same permission their own screen needs.')
                ->send();
            return;
        }

        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($id);
        if (! $customer) return;

        $result = app(CustomerRemovalService::class)
            ->remove($customer, Auth::guard('web')->id(), 'master-admin');

        Notification::make()->success()
            ->title($result['mode'] === 'delete' ? 'Customer deleted' : 'Customer erased')
            ->body($result['mode'] === 'delete'
                ? 'Nothing referenced them, so the row is gone.'
                : 'Their personal details are gone; the row stays because sales or bookings reference it.')
            ->send();
    }

    public function openWindow(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $until = now()->addDays(10);
        $tenant->forceFill(['consent_cleanup_until' => $until])->save();

        logger()->info('MARKER-CUST-CLEANUP window opened', [
            'tenant_id' => $tenant->id, 'until' => $until->toIso8601String(),
            'by' => Auth::guard('web')->id(),
        ]);

        Notification::make()->success()
            ->title('Customer admin mode opened for 10 days')
            ->body('The shop can also use it on their own customer screen. It closes on its own.')
            ->send();
    }
}
