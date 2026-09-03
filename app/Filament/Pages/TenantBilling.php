<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Models\TenantBillingDiscount;
use App\Models\PlatformSettings;
use App\Models\TenantChargeRun;
use App\Services\Billing\ChargeService;
use App\Services\Billing\StatementService;
use App\Services\EmailLedger;
use App\Support\AdminAccess;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
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

    // MARKER-BILLING-CONTROLS
    public string $thresholdDollars = '';
    public string $allowanceOverride = '';   // MARKER-ALLOWANCE-TIERS
    public string $resolveReason    = '';
    public ?string $resolvingRunId  = null;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->tenantId = request()->query('tenant');
        $this->month    = now()->format('Y-m');
        $this->syncThreshold(); // MARKER-BILLING-CONTROLS
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

    // ---- MARKER-BILLING-CONTROLS ------------------------------------

    public function updatedTenantId(): void
    {
        $this->syncThreshold();
    }

    private function syncThreshold(): void
    {
        $tenant = $this->tenant();
        // MARKER-ALLOWANCE-TIERS — blank means "use whatever the tier includes".
        $this->allowanceOverride = $tenant && $tenant->email_free_monthly !== null
            ? (string) $tenant->email_free_monthly
            : '';
        $this->thresholdDollars = $tenant && $tenant->charge_threshold_cents !== null
            ? number_format($tenant->charge_threshold_cents / 100, 2, '.', '')
            : '';
    }

    /** MARKER-ALLOWANCE-TIERS — what this shop gets, and where that came from. */
    public function allowanceState(): array
    {
        $tenant = $this->tenant();
        if (! $tenant) return ['effective' => 0, 'tier' => 0, 'overridden' => false];

        return [
            'effective'  => \App\Services\EmailLedger::freeAllowance($tenant->id),
            'tier'       => \App\Services\EmailLedger::tierAllowance($tenant->plan_tier),
            'tier_name'  => ucfirst((string) $tenant->plan_tier),
            'overridden' => $tenant->email_free_monthly !== null,
        ];
    }

    public function saveAllowance(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $raw = trim($this->allowanceOverride);
        $tenant->forceFill([
            'email_free_monthly' => $raw === '' ? null : max(0, (int) $raw),
        ])->save();

        logger()->info('MARKER-ALLOWANCE-TIERS override set', [
            'tenant' => $tenant->id, 'value' => $raw === '' ? null : (int) $raw,
            'by' => Auth::guard('web')->id(),
        ]);

        Notification::make()->success()
            ->title($raw === '' ? 'Using the plan\'s allowance' : 'Allowance set for this shop')
            ->body($raw === ''
                ? 'This shop now gets whatever its plan includes.'
                : number_format((int) $raw) . ' free emails a month, regardless of plan.')
            ->send();
    }

    public function chargingState(): array
    {
        $tenant = $this->tenant();
        $svc    = app(ChargeService::class);

        return [
            'master'     => (bool) (PlatformSettings::current()->charging_enabled ?? false),
            'tenant'     => (bool) ($tenant?->charging_enabled),
            'has_card'   => (bool) ($tenant?->stripe_payment_method_id),
            'can_charge' => $tenant ? $svc->canCharge($tenant) : false,
            'threshold'  => $tenant ? $svc->threshold($tenant) : 0,
            'unbilled'   => $tenant ? $svc->unbilledCents($tenant) : 0,
            'default'    => (int) (PlatformSettings::current()->charge_threshold_default_cents ?? 2500),
            'paused'     => $tenant?->campaigns_paused_at,
        ];
    }

    /** MARKER-BILLING-NOTICES — what we told them, and what they did next. */
    public function notices()
    {
        $tenant = $this->tenant();
        if (! $tenant) return collect();

        return \App\Models\BillingNotice::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->limit(20)->get();
    }

    public function runs()
    {
        $tenant = $this->tenant();
        if (! $tenant) return collect();

        return TenantChargeRun::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->limit(20)->get();
    }

    public function toggleCharging(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $now = ! $tenant->charging_enabled;
        $tenant->forceFill(['charging_enabled' => $now])->save();

        logger()->info('MARKER-BILLING-CONTROLS charging toggled', [
            'tenant' => $tenant->id, 'enabled' => $now, 'by' => Auth::guard('web')->id(),
        ]);

        Notification::make()
            ->title($now ? 'Charging enabled for this shop' : 'Charging disabled for this shop')
            ->body($now
                ? 'Balances over the threshold will be charged to the card on file.'
                : 'Usage keeps accruing; nothing will be charged.')
            ->success()->send();
    }

    public function saveThreshold(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $raw = trim($this->thresholdDollars);
        $tenant->forceFill([
            'charge_threshold_cents' => $raw === '' ? null : (int) round(((float) $raw) * 100),
        ])->save();

        Notification::make()->title('Threshold saved')
            ->body($raw === '' ? 'Using the platform default.' : 'Charging at $' . number_format((float) $raw, 2) . '.')
            ->success()->send();
    }

    /** Settle now rather than waiting for the hourly pass — for testing. */
    public function chargeNow(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) return;

        $svc = app(ChargeService::class);
        if (! $svc->canCharge($tenant)) {
            Notification::make()->danger()->title('Cannot charge')
                ->body('Charging is off, or there is no card on file.')->send();
            return;
        }

        $run = $svc->claim($tenant);
        if (! $run) {
            Notification::make()->title('Nothing to charge')
                ->body('No unbilled usage.')->send();
            return;
        }

        $run = $svc->charge($run);

        $run->status === TenantChargeRun::CHARGED
            ? Notification::make()->success()->title('Charged ' . self::money($run->amount_cents))->send()
            : Notification::make()->danger()->title('Charge failed')
                ->body($run->failure_message ?: 'See the run below.')->send();
    }

    public function startResolve(string $runId): void
    {
        $this->resolvingRunId = $runId;
        $this->resolveReason  = '';
    }

    public function cancelResolve(): void
    {
        $this->resolvingRunId = null;
        $this->resolveReason  = '';
    }

    public function refundRun(): void
    {
        $run = TenantChargeRun::find($this->resolvingRunId);
        if (! $run || trim($this->resolveReason) === '') {
            Notification::make()->danger()->title('A reason is required')
                ->body('Six months from now, somebody will ask why.')->send();
            return;
        }

        $ok = app(ChargeService::class)->refund($run, trim($this->resolveReason), Auth::guard('web')->user()?->email);

        $ok
            ? Notification::make()->success()->title('Refunded ' . self::money($run->amount_cents))->send()
            : Notification::make()->danger()->title('Refund failed')->body('Only a charged run can be refunded.')->send();

        $this->cancelResolve();
    }

    public function writeOffRun(): void
    {
        $run = TenantChargeRun::find($this->resolvingRunId);
        if (! $run || trim($this->resolveReason) === '') {
            Notification::make()->danger()->title('A reason is required')
                ->body('Six months from now, somebody will ask why.')->send();
            return;
        }

        $ok = app(ChargeService::class)->writeOff($run, trim($this->resolveReason), Auth::guard('web')->user()?->email);

        $ok
            ? Notification::make()->success()->title('Written off')
                ->body('No money moved. Those messages will never be charged again.')->send()
            : Notification::make()->danger()->title('Cannot write off')
                ->body('That run was already settled with money — refund it instead.')->send();

        $this->cancelResolve();
    }

    public static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
