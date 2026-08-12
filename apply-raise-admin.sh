#!/usr/bin/env bash
set -euo pipefail

# apply-raise-admin.sh — MARKER-RAISE-ADMIN
# Minimum raise admin: investors table, commitments, funds received, cap-table math at the $1M cap,
# plus the invest-site link (views, leads, rotate). Requires MARKER-INVEST-SITE to be applied first.

echo "==> checking repo root"
test -f artisan || { echo "run this from the intake-license repo root"; exit 1; }

PROVIDER=app/Providers/Filament/AdminPanelProvider.php
test -f "$PROVIDER" || { echo "missing $PROVIDER"; exit 1; }

grep -q "MARKER-INVEST-SITE" routes/web.php || { echo "apply-invest-site.sh must be applied first"; exit 1; }

if grep -q "MARKER-RAISE-ADMIN" "$PROVIDER"; then
  echo "MARKER-RAISE-ADMIN already registered — nothing to do."
  exit 0
fi

echo "==> creating directories"
mkdir -p app/Filament/Pages app/Models database/migrations resources/views/filament/pages

echo "==> migration"
cat > database/migrations/2026_08_12_200000_create_investors_table.php <<'MIGEOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-ADMIN
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('entity')->nullable();
            $table->unsignedInteger('amount')->default(0);          // committed, whole dollars
            $table->unsignedInteger('amount_received')->default(0); // wired, whole dollars
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('funding_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
MIGEOF

echo "==> model"
cat > app/Models/Investor.php <<'MODEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-ADMIN
class Investor extends Model
{
    /** The SAFE cap the round is priced against. Change here and the cap table follows. */
    public const CAP    = 1000000;
    public const TARGET = 100000;

    protected $fillable = [
        'name', 'email', 'entity', 'amount', 'amount_received',
        'invited_at', 'committed_at', 'signed_at', 'funded_at', 'declined_at',
        'funding_method', 'notes',
    ];

    protected $casts = [
        'invited_at'   => 'datetime',
        'committed_at' => 'datetime',
        'signed_at'    => 'datetime',
        'funded_at'    => 'datetime',
        'declined_at'  => 'datetime',
    ];

    /** Status is DERIVED from events, never typed. Declined is the one manual state. */
    public function getStatusAttribute(): string
    {
        if ($this->declined_at)  return 'Declined';
        if ($this->funded_at)    return 'Funded';
        if ($this->signed_at)    return 'Signed';
        if ($this->committed_at) return 'Committed';
        if ($this->invited_at)   return 'Invited';

        return 'Added';
    }

    public function getPercentAttribute(): float
    {
        return self::CAP > 0 ? round($this->amount / self::CAP * 100, 2) : 0.0;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('declined_at');
    }
}
MODEOF

echo "==> filament page"
cat > app/Filament/Pages/Raise.php <<'PAGEOF'
<?php

namespace App\Filament\Pages;

use App\Models\InvestLead;
use App\Models\Investor;
use App\Models\InvestToken;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

// MARKER-RAISE-ADMIN
class Raise extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Raise';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $title           = 'Raise';
    protected static ?int    $navigationSort  = 90;

    protected static string $view = 'filament.pages.raise';

    // add-investor form
    public string $name   = '';
    public string $email  = '';
    public string $entity = '';
    public $amount        = '';

    public function addInvestor(): void
    {
        $data = $this->validate([
            'name'   => ['required', 'string', 'max:190'],
            'email'  => ['nullable', 'email', 'max:190'],
            'entity' => ['nullable', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);

        Investor::create([
            'name'         => $data['name'],
            'email'        => $data['email'] ?: null,
            'entity'       => $data['entity'] ?: null,
            'amount'       => (int) $data['amount'],
            'committed_at' => now(),
        ]);

        $this->reset(['name', 'email', 'entity', 'amount']);

        Notification::make()->title('Investor added')->success()->send();
    }

    public function markSigned(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['signed_at' => now(), 'declined_at' => null])->save();

        Notification::make()->title($investor->name . ' marked signed')->success()->send();
    }

    public function markFunded(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill([
            'funded_at'       => now(),
            'signed_at'       => $investor->signed_at ?: now(),
            'amount_received' => $investor->amount_received ?: $investor->amount,
            'declined_at'     => null,
        ])->save();

        Notification::make()->title('Funds recorded for ' . $investor->name)->success()->send();
    }

    public function markDeclined(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['declined_at' => now()])->save();

        Notification::make()->title($investor->name . ' marked declined')->send();
    }

    public function reopen(int $id): void
    {
        $investor = Investor::findOrFail($id);
        $investor->forceFill(['declined_at' => null])->save();

        Notification::make()->title($investor->name . ' reopened')->send();
    }

    public function rotateInviteLink(): void
    {
        $token = InvestToken::rotate('rotated from master admin');

        Notification::make()
            ->title('New link issued')
            ->body('Every previously shared link is now dead.')
            ->warning()
            ->send();
    }

    public function getViewData(): array
    {
        $investors = Investor::orderByRaw('funded_at is null')
            ->orderByDesc('amount')
            ->get();

        $active = $investors->whereNull('declined_at');

        return [
            'investors'  => $investors,
            'committed'  => (int) $active->sum('amount'),
            'received'   => (int) $active->sum('amount_received'),
            'target'     => Investor::TARGET,
            'cap'        => Investor::CAP,
            'token'      => InvestToken::current(),
            'leads'      => InvestLead::latest()->limit(25)->get(),
            'leadCount'  => InvestLead::count(),
        ];
    }
}
PAGEOF

echo "==> page view"
cat > resources/views/filament/pages/raise.blade.php <<'VIEWEOF'
<x-filament-panels::page>
<!-- MARKER-RAISE-ADMIN -->

@php
    $pct = fn ($n) => $cap > 0 ? number_format($n / $cap * 100, 2) . '%' : '0%';
    $usd = fn ($n) => '$' . number_format($n);
    $progress = $target > 0 ? min(100, round($committed / $target * 100)) : 0;
@endphp

<div class="grid gap-4 md:grid-cols-4">
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-2xl font-bold">{{ $usd($committed) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Committed of {{ $usd($target) }}</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-2xl font-bold">{{ $usd($received) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Funds received</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-2xl font-bold">{{ $pct($committed) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Sold at {{ $usd($cap) }} cap</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-2xl font-bold">{{ $progress }}%</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Of target</div>
    </div>
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Add a commitment</div>
    <div class="grid gap-3 md:grid-cols-5">
        <input wire:model="name"   placeholder="Name"   class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <input wire:model="email"  placeholder="Email"  class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <input wire:model="entity" placeholder="Entity (optional)" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <input wire:model="amount" placeholder="Amount" type="number" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <x-filament::button wire:click="addInvestor">Add</x-filament::button>
    </div>
    @error('name')   <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('email')  <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('amount') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
</div>

<div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
    <table class="w-full text-sm">
        <thead class="text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="text-left p-3">Investor</th>
                <th class="text-left p-3">Status</th>
                <th class="text-right p-3">Committed</th>
                <th class="text-right p-3">Received</th>
                <th class="text-right p-3">Equity</th>
                <th class="text-right p-3">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($investors as $investor)
            <tr class="border-t border-gray-100 dark:border-white/5 {{ $investor->declined_at ? 'opacity-50' : '' }}">
                <td class="p-3">
                    <div class="font-medium">{{ $investor->name }}</div>
                    <div class="text-xs text-gray-500">{{ $investor->email }}{{ $investor->entity ? ' · ' . $investor->entity : '' }}</div>
                </td>
                <td class="p-3">{{ $investor->status }}</td>
                <td class="p-3 text-right">{{ $usd($investor->amount) }}</td>
                <td class="p-3 text-right">{{ $investor->amount_received ? $usd($investor->amount_received) : '—' }}</td>
                <td class="p-3 text-right">{{ $investor->percent }}%</td>
                <td class="p-3 text-right whitespace-nowrap">
                    @if ($investor->declined_at)
                        <x-filament::button size="xs" color="gray" wire:click="reopen({{ $investor->id }})">Reopen</x-filament::button>
                    @else
                        @unless ($investor->signed_at)
                            <x-filament::button size="xs" color="gray" wire:click="markSigned({{ $investor->id }})">Signed</x-filament::button>
                        @endunless
                        @unless ($investor->funded_at)
                            <x-filament::button size="xs"
                                wire:click="markFunded({{ $investor->id }})"
                                wire:confirm="Record {{ $usd($investor->amount) }} received from {{ $investor->name }}?">Funded</x-filament::button>
                            <x-filament::button size="xs" color="danger"
                                wire:click="markDeclined({{ $investor->id }})"
                                wire:confirm="Mark {{ $investor->name }} declined?">Declined</x-filament::button>
                        @endunless
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-6 text-center text-gray-500">No commitments recorded yet.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr class="border-t border-gray-200 dark:border-white/10 font-semibold">
                <td class="p-3">Founder</td>
                <td class="p-3">—</td>
                <td class="p-3 text-right">—</td>
                <td class="p-3 text-right">—</td>
                <td class="p-3 text-right">{{ number_format(100 - ($cap > 0 ? $committed / $cap * 100 : 0), 2) }}%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<p class="mt-3 text-xs text-gray-500">
    Working view only, not a cap table of record. Percentages assume every commitment converts at the
    {{ $usd($cap) }} post-money cap with no option pool modelled; a priced round will dilute the founder line.
    Intake files nothing with the SEC or any state — Form D and blue-sky filings are yours to make.
</p>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Shared invitation link</div>
            @if ($token)
                <div class="font-mono text-sm break-all">{{ url('/invest/' . $token->token) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $token->views }} views · {{ $leadCount }} leads</div>
            @else
                <div class="text-sm text-gray-500">No link issued yet.</div>
            @endif
        </div>
        <x-filament::button color="warning"
            wire:click="rotateInviteLink"
            wire:confirm="Issue a new link? Every copy of the current link stops working immediately.">
            Rotate link
        </x-filament::button>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        A freely forwardable link starts to look like general solicitation, which Reg D 506(b) does not allow.
        Share it deliberately, and rotate it if it travels further than intended.
    </p>
</div>

@if ($leads->isNotEmpty())
<div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
    <table class="w-full text-sm">
        <thead class="text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="text-left p-3">Lead</th>
                <th class="text-left p-3">Note</th>
                <th class="text-right p-3">Received</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($leads as $lead)
            <tr class="border-t border-gray-100 dark:border-white/5">
                <td class="p-3">
                    <div class="font-medium">{{ $lead->name }}</div>
                    <div class="text-xs text-gray-500">{{ $lead->email }}</div>
                </td>
                <td class="p-3 text-gray-500">{{ $lead->note }}</td>
                <td class="p-3 text-right text-gray-500">{{ $lead->created_at?->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

</x-filament-panels::page>
VIEWEOF

echo "==> registering page in AdminPanelProvider"
python3 - <<'PYEOF'
import io
p = "app/Providers/Filament/AdminPanelProvider.php"
src = io.open(p, encoding="utf-8").read()

anchor = "\\App\\Filament\\Pages\\MarketingTraffic::class,"
assert src.count(anchor) == 1, "anchor MarketingTraffic::class found %d times — aborting" % src.count(anchor)

addition = anchor + "\n                \\App\\Filament\\Pages\\Raise::class, // MARKER-RAISE-ADMIN — panel lists pages explicitly, no auto-discovery"
src = src.replace(anchor, addition, 1)
io.open(p, "w", encoding="utf-8").write(src)
print("   registered Raise::class")
PYEOF

grep -q "MARKER-RAISE-ADMIN" "$PROVIDER" || { echo "REGISTRATION FAILED — page would 404"; exit 1; }

echo ""
echo "MARKER-RAISE-ADMIN applied."
echo "  files: investors migration, Investor model, Filament\\Pages\\Raise, raise.blade.php"
echo "  AdminPanelProvider ->pages([]) updated"
echo ""
echo "Deploy runs migrate; then on the server: php artisan filament:cache-components"
echo "Page lands at intake.works/admin/raise"
