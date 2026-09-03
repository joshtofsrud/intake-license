<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Models\TenantBillingDiscount;
use App\Services\Billing\StatementService;
use App\Services\EmailLedger;
use App\Support\AdminAccess;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-TENANT-BILLING — one shop's money, from the shop's own numbers.
 *
 * Deliberately reads through StatementService rather than querying directly:
 * two implementations of "what does this month cost" is how a support call
 * ends with two people reading different totals off two screens.
 */
class TenantBilling extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Tenant billing';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 85;
    protected static string  $view            = 'filament.pages.tenant-billing';
    protected static ?string $slug            = 'tenant-billing';

    public ?string $tenantId = null;
    public string  $month    = '';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->tenantId = request()->query('tenant');
        $this->month    = now()->format('Y-m');
    }

    public function tenants()
    {
        return Tenant::where('is_platform', false)->orderBy('name')->get(['id', 'name', 'subdomain', 'is_demo']);
    }

    public function tenant(): ?Tenant
    {
        return $this->tenantId ? Tenant::find($this->tenantId) : null;
    }

    /**
     * MARKER-STATEMENT-HISTORY — months this shop actually existed for. Six
     * months from today would offer April to a shop created in August, and the
     * statement for it would be invented.
     */
    public function monthOptions(): array
    {
        $tenant = $this->tenant();
        $first  = $tenant && $tenant->created_at
            ? CarbonImmutable::parse($tenant->created_at)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth()->subMonths(5);

        $out = [];
        for ($i = 0; $i < 24; $i++) {
            $m = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            if ($m->lt($first)) break;
            $out[$m->format('Y-m')] = $m->format('F Y');
        }
        return $out;
    }

    public function statement(): ?array
    {
        $tenant = $this->tenant();
        if (! $tenant) return null;

        $start = CarbonImmutable::createFromFormat('Y-m-d', $this->month . '-01');
        return app(StatementService::class)->for($tenant, $start);
    }

    /** Everything metered and not yet invoiced — the number a charge would take. */
    public function balance(): array
    {
        $tenant = $this->tenant();
        if (! $tenant) return ['messages' => 0, 'cents' => 0];

        $row = TenantEmailLedgerEntry::where('tenant_id', $tenant->id)
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->selectRaw('COUNT(*) n, SUM(rate * segments) spend')
            ->first();

        return [
            'messages' => (int) ($row->n ?? 0),
            'cents'    => (int) round(((float) ($row->spend ?? 0)) * 100),
        ];
    }

    public function capState(): ?array
    {
        $tenant = $this->tenant();
        return $tenant ? EmailLedger::capState($tenant) : null;
    }

    public function allowance(): int
    {
        $tenant = $this->tenant();
        return $tenant ? EmailLedger::freeAllowance($tenant->id) : 0;
    }

    public function discounts()
    {
        $tenant = $this->tenant();
        if (! $tenant) return collect();

        return TenantBillingDiscount::where('tenant_id', $tenant->id)
            ->orderByDesc('starts_on')->get();
    }

    /** Who pays the carrier for this shop's texts. */
    public function smsOwnership(): string
    {
        $tenant = $this->tenant();
        if (! $tenant) return '—';

        return ($tenant->twilio_account_sid && $tenant->twilio_auth_token)
            ? "The shop's own Twilio — segments metered at $0.00"
            : 'Intake-provided — segments billed';
    }

    public static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
