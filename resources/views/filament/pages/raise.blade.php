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
