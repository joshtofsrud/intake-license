#!/usr/bin/env bash
# apply-commission-ledger.sh — Commission ledger on COLLECTED revenue.
#
# How it works:
#   Stripe invoice.paid (the platform billing webhook you already run) ->
#   CommissionAccrualService: if the tenant traces to a deal-registered
#   agency's prospect, write ONE ledger entry per invoice (unique on
#   stripe_invoice_id, so webhook retries can't double-pay):
#     rate = agency's year-1 rate while the tenant is < 12 months old
#            (measured from tenants.created_at), else the residual rate.
#     commission = collected amount x rate, snapshotted in cents.
#   The accrual is wrapped fail-open — a ledger error never breaks billing.
#
# Surfaces:
#   Master admin: Commissions tab on each agency (mark paid, filters) +
#     an "Unpaid comm" column on the agencies list.
#   Rep panel: dashboard stats — open prospects, due today, won tenants,
#     unpaid commission (principal sees agency-wide, rep sees their own).
#
# Notes / decisions baked in (say the word to change either):
#   - Account age basis = tenants.created_at.
#   - deal_registration = OFF on an agency -> no auto-accrual (case-by-case).
#   - Forward-only: stripe_webhook_events stores no payloads, so there is no
#     history to backfill. Modus starts at zero anyway.
#
# PREREQUISITE: apply-rep-panel.sh applied.
# Run from the repo root:  bash apply-commission-ledger.sh
# Idempotent: guarded on MARKER-LEDGER-SERVICE.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
grep -q MARKER-REPPANEL-GATE app/Models/User.php 2>/dev/null || { echo "ERROR: run apply-rep-panel.sh first."; exit 1; }
if [ -f app/Services/CommissionAccrualService.php ] && grep -q MARKER-LEDGER-SERVICE app/Services/CommissionAccrualService.php; then
  echo "apply-commission-ledger.sh: already applied — skipping."; exit 0
fi

echo "Applying commission ledger …"

# ─────────────────────────────────────────────────────────────────────────────
# migration  (MARKER-LEDGER-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000006_create_sales_commission_entries.php <<'EOF'
<?php
// MARKER-LEDGER-CORE — one row per collected platform invoice for an
// attributed tenant. stripe_invoice_id is UNIQUE: webhook retries and
// replays can never double-accrue. rate + commission are snapshotted at
// accrual time (design principle 13) — changing an agency's rate later
// affects future entries only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_commission_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agency_id');
            $t->uuid('sales_rep_id')->nullable();
            $t->uuid('sales_prospect_id')->nullable();
            $t->uuid('tenant_id');

            $t->string('stripe_invoice_id', 255)->unique();
            $t->unsignedInteger('amount_collected_cents');
            $t->decimal('rate', 5, 4);
            $t->unsignedInteger('commission_cents');
            $t->string('basis', 12);                       // year1 | residual
            $t->timestamp('collected_at');

            $t->string('status', 12)->default('accrued');  // accrued | paid | void
            $t->timestamp('paid_at')->nullable();

            $t->timestamps();

            $t->foreign('agency_id', 'commission_entries_agency_fk')
              ->references('id')->on('sales_agencies')->cascadeOnDelete();
            $t->index(['agency_id', 'status']);
            $t->index('tenant_id');
            $t->index('sales_rep_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commission_entries');
    }
};
EOF
echo "  wrote migration create_sales_commission_entries"

# ─────────────────────────────────────────────────────────────────────────────
# model  (MARKER-LEDGER-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Models/SalesCommissionEntry.php <<'EOF'
<?php
// MARKER-LEDGER-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommissionEntry extends Model
{
    use HasUuids;

    protected $table = 'sales_commission_entries';

    protected $fillable = [
        'agency_id', 'sales_rep_id', 'sales_prospect_id', 'tenant_id',
        'stripe_invoice_id', 'amount_collected_cents', 'rate',
        'commission_cents', 'basis', 'collected_at', 'status', 'paid_at',
    ];

    protected $casts = [
        'rate'         => 'decimal:4',
        'collected_at' => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public const STATUSES = [
        'accrued' => 'Accrued',
        'paid'    => 'Paid',
        'void'    => 'Void',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(SalesRep::class, 'sales_rep_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', 'accrued');
    }
}
EOF
echo "  wrote SalesCommissionEntry model"

# ─────────────────────────────────────────────────────────────────────────────
# service  (MARKER-LEDGER-SERVICE)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Services/CommissionAccrualService.php <<'EOF'
<?php
// MARKER-LEDGER-SERVICE — turns a collected platform invoice into a ledger
// entry when the tenant traces to a deal-registered agency.

namespace App\Services;

use App\Models\SalesCommissionEntry;
use App\Models\SalesProspect;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class CommissionAccrualService
{
    public function recordInvoicePaid(Tenant $tenant, object $invoice): ?SalesCommissionEntry
    {
        $invoiceId = $invoice->id ?? null;
        $amount    = (int) ($invoice->amount_paid ?? 0);
        if (! $invoiceId || $amount <= 0) {
            return null;
        }

        // Attribution: prospect -> agency. No attributed prospect, no accrual.
        $prospect = SalesProspect::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('agency_id')
            ->with('agency')
            ->first();

        $agency = $prospect?->agency;
        if (! $agency) {
            return null;
        }

        // deal_registration OFF = commissions decided case-by-case, not auto.
        if (! $agency->deal_registration) {
            return null;
        }

        // Idempotency: one entry per Stripe invoice, ever.
        if (SalesCommissionEntry::where('stripe_invoice_id', $invoiceId)->exists()) {
            return null;
        }

        // Account age from tenants.created_at: < 12 months = year-1 rate.
        $ageMonths = (int) $tenant->created_at->diffInMonths(now());
        $basis     = $ageMonths < 12 ? 'year1' : 'residual';
        $rate      = $basis === 'year1'
            ? (float) $agency->commission_year1
            : (float) $agency->commission_residual;

        $entry = SalesCommissionEntry::create([
            'agency_id'              => $agency->id,
            'sales_rep_id'           => $prospect->sales_rep_id,
            'sales_prospect_id'      => $prospect->id,
            'tenant_id'              => $tenant->id,
            'stripe_invoice_id'      => $invoiceId,
            'amount_collected_cents' => $amount,
            'rate'                   => $rate,
            'commission_cents'       => (int) round($amount * $rate),
            'basis'                  => $basis,
            'collected_at'           => now(),
        ]);

        Log::info('[Commission] accrued', [
            'tenant'  => $tenant->subdomain,
            'agency'  => $agency->slug,
            'invoice' => $invoiceId,
            'basis'   => $basis,
            'cents'   => $entry->commission_cents,
        ]);

        return $entry;
    }
}
EOF
echo "  wrote CommissionAccrualService"

# ─────────────────────────────────────────────────────────────────────────────
# master admin: Commissions relation manager  (MARKER-LEDGER-ADMIN)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Filament/Resources/SalesAgencyResource/RelationManagers/CommissionsRelationManager.php <<'EOF'
<?php
// MARKER-LEDGER-ADMIN

namespace App\Filament\Resources\SalesAgencyResource\RelationManagers;

use App\Models\SalesCommissionEntry;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionEntries';
    protected static ?string $title = 'Commissions';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('collected_at')
                    ->label('Collected')->dateTime('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')->weight('semibold')
                    ->description(fn (SalesCommissionEntry $r) => $r->tenant?->subdomain),
                Tables\Columns\TextColumn::make('rep.name')
                    ->label('Rep')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount_collected_cents')
                    ->label('Collected $')->alignEnd()
                    ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2)),
                Tables\Columns\TextColumn::make('rate')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state * 100, 2), '0'), '.') . '%'),
                Tables\Columns\TextColumn::make('commission_cents')
                    ->label('Commission')->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                    ->summarize(Tables\Columns\Summarizers\Summarizer::make()
                        ->using(fn ($query) => '$' . number_format(((clone $query)->sum('commission_cents')) / 100, 2))),
                Tables\Columns\TextColumn::make('basis')
                    ->badge()
                    ->color(fn ($state) => $state === 'year1' ? 'primary' : 'success')
                    ->formatStateUsing(fn ($state) => $state === 'year1' ? 'Yr 1' : 'Residual'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesCommissionEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'paid'    => 'success',
                        'accrued' => 'warning',
                        default   => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesCommissionEntry::STATUSES)->default('accrued'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records
                        ->filter(fn (SalesCommissionEntry $r) => $r->status === 'accrued')
                        ->each(fn (SalesCommissionEntry $r) => $r->update(['status' => 'paid', 'paid_at' => now()]))),
            ])
            ->defaultSort('collected_at', 'desc');
    }
}
EOF
echo "  wrote CommissionsRelationManager"

# ─────────────────────────────────────────────────────────────────────────────
# rep panel: dashboard widget  (MARKER-LEDGER-REPWIDGET)
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p app/Filament/Rep/Widgets
cat > app/Filament/Rep/Widgets/RepBookWidget.php <<'EOF'
<?php
// MARKER-LEDGER-REPWIDGET — the rep's book at a glance.
// Principal: agency-wide numbers. Rep: their own.

namespace App\Filament\Rep\Widgets;

use App\Models\SalesCommissionEntry;
use App\Models\SalesProspect;
use App\Models\SalesRep;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RepBookWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $rep = SalesRep::with('agency')->where('user_id', auth()->id())->first();
        if (! $rep) {
            return [];
        }

        $isPrincipal = $rep->role === 'principal';

        $prospects = SalesProspect::query()->when(
            $isPrincipal,
            fn ($query) => $query->where('agency_id', $rep->agency_id),
            fn ($query) => $query->where('sales_rep_id', $rep->id),
        );

        $entries = SalesCommissionEntry::query()->when(
            $isPrincipal,
            fn ($query) => $query->where('agency_id', $rep->agency_id),
            fn ($query) => $query->where('sales_rep_id', $rep->id),
        );

        $open = (clone $prospects)->whereNotIn('stage', ['won', 'lost'])->count();
        $due  = (clone $prospects)->whereNotIn('stage', ['won', 'lost'])
            ->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', today())->count();
        $won  = (clone $prospects)->whereNotNull('tenant_id')->count();
        $unpaidCents = (clone $entries)->where('status', 'accrued')->sum('commission_cents');
        $paidCents   = (clone $entries)->where('status', 'paid')->sum('commission_cents');

        $scope = $isPrincipal ? ($rep->agency?->name ?? 'Agency') : 'Your book';

        return [
            Stat::make('Open prospects', $open)
                ->description($scope),
            Stat::make('Due today', $due)
                ->description('next actions due or overdue')
                ->color($due > 0 ? 'warning' : 'gray'),
            Stat::make('Won tenants', $won)
                ->description('converted to Intake')
                ->color('success'),
            Stat::make('Commission unpaid', '$' . number_format($unpaidCents / 100, 2))
                ->description('$' . number_format($paidCents / 100, 2) . ' paid to date')
                ->color($unpaidCents > 0 ? 'warning' : 'gray'),
        ];
    }
}
EOF
echo "  wrote RepBookWidget"

# ─────────────────────────────────────────────────────────────────────────────
# Anchored edits: webhook hook, agency model+resource, rep panel widgets
# ─────────────────────────────────────────────────────────────────────────────
python3 - <<'PYEOF'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:70]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

# --- webhook: accrue after the tenant is marked active (fail open) ---
WH = "app/Http/Controllers/Webhooks/StripeWebhookController.php"
edit(WH,
"""        $tenant->update($updates);

        Log::info('[StripeWebhook] tenant marked active', [
            'tenant' => $tenant->subdomain,
        ]);
    }""",
"""        $tenant->update($updates);

        Log::info('[StripeWebhook] tenant marked active', [
            'tenant' => $tenant->subdomain,
        ]);

        // MARKER-LEDGER-HOOK — accrue rep commission on collected revenue.
        // Fail open (principle 15): a ledger error never breaks billing.
        try {
            app(\\App\\Services\\CommissionAccrualService::class)->recordInvoicePaid($tenant, $invoice);
        } catch (\\Throwable $e) {
            Log::warning('[StripeWebhook] commission accrual failed', [
                'tenant' => $tenant->subdomain,
                'error'  => $e->getMessage(),
            ]);
        }
    }""")

# --- SalesAgency: commissionEntries relation ---
A = "app/Models/SalesAgency.php"
edit(A,
"""    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'agency_id');
    }""",
"""    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'agency_id');
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(SalesCommissionEntry::class, 'agency_id');
    }""")

# --- SalesAgencyResource: unpaid sum column + relation manager registration ---
R = "app/Filament/Resources/SalesAgencyResource.php"
edit(R,
"""                    'prospects as tenants_count' => fn (Builder $builder) => $builder->whereNotNull('tenant_id'),
                ]))""",
"""                    'prospects as tenants_count' => fn (Builder $builder) => $builder->whereNotNull('tenant_id'),
                ])
                ->withSum(['commissionEntries as unpaid_commission_cents' => fn (Builder $builder) => $builder->where('status', 'accrued')], 'commission_cents'))""")
edit(R,
"""                Tables\\Columns\\IconColumn::make('deal_registration')
                    ->label('Deal reg')->boolean()->toggleable(),""",
"""                Tables\\Columns\\TextColumn::make('unpaid_commission_cents')
                    ->label('Unpaid comm')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => '$' . number_format(((int) $state) / 100, 2))
                    ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'gray'),
                Tables\\Columns\\IconColumn::make('deal_registration')
                    ->label('Deal reg')->boolean()->toggleable(),""")
edit(R,
"""        return [
            RelationManagers\\RepsRelationManager::class,
        ];""",
"""        return [
            RelationManagers\\RepsRelationManager::class,
            RelationManagers\\CommissionsRelationManager::class,
        ];""")

# --- RepPanelProvider: register the dashboard widget ---
P = "app/Providers/Filament/RepPanelProvider.php"
edit(P,
"""            ->pages([
                Dashboard::class,
            ])""",
"""            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                \\App\\Filament\\Rep\\Widgets\\RepBookWidget::class,
            ])""")

print("All anchored edits applied.")
PYEOF

echo ""
echo "Done. Next:"
echo "  composer dump-autoload && php artisan migrate --force && php artisan filament:cache-components && php artisan optimize:clear"
