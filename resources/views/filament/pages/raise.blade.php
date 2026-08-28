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

<!-- MARKER-RAISE-INVITE -->
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Invite someone</div>

    <label class="block mb-3"><span class="text-xs text-gray-500">Your message — goes above the standard wording</span>
        <textarea wire:model="inviteMessage" rows="3"
                  placeholder="Hi {name} — you mentioned wanting a look at what I've been building…"
                  class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>

    <div class="grid gap-3 md:grid-cols-3">
        <input wire:model="inviteName"  placeholder="Name" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <input wire:model="inviteEmail" placeholder="Email" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        <x-filament::button wire:click="inviteOne">Invite one</x-filament::button>
    </div>
    @error('inviteName')    <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('inviteEmail')   <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('inviteMessage') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror

    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10">
        <label class="block"><span class="text-xs text-gray-500">Or paste a list — one per line, <code>Name &lt;email&gt;</code></span>
            <textarea wire:model="inviteList" rows="4"
                      placeholder="Jane Ellery &lt;jane@example.com&gt;&#10;Marcus Hale, marcus@example.com"
                      class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>
        <div class="mt-3 flex items-center gap-3">
            <x-filament::button wire:click="previewList" color="gray">Preview list</x-filament::button>
            @if ($invitePreview)
                <x-filament::button wire:click="sendList">
                    Send {{ collect($invitePreview)->whereNull('problem')->count() }} invitation(s)
                </x-filament::button>
            @endif
            <span class="text-xs text-gray-500">Nothing sends until you have previewed it.</span>
        </div>

        @if ($invitePreview)
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500">
                        <tr><th class="text-left p-2">Name</th><th class="text-left p-2">Email</th><th class="text-left p-2">Will send?</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($invitePreview as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="p-2">{{ $row['name'] ?: '—' }}</td>
                                <td class="p-2">{{ $row['email'] ?: $row['line'] }}</td>
                                <td class="p-2">
                                    @if ($row['problem'])
                                        <span class="text-danger-600">Skipped — {{ $row['problem'] }}</span>
                                    @else
                                        <span class="text-success-600">Yes</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Everyone invited gets their <b>own</b> record and their own link, so a pasted list is a batch of
        individual emails rather than one email to a group — you can see who opened, and withdraw one
        without touching the rest. An invitation commits nobody to anything: they enter their own entity
        and amount from their link, and only then does a commitment exist.
    </p>
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

<!-- MARKER-RAISE-INVITE — invited and silent, kept apart from commitments -->
@if ($invited->isNotEmpty())
<div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
    <div class="p-3 text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200 dark:border-white/10">
        Invited — no response yet ({{ $invited->count() }})
    </div>
    <table class="w-full text-sm">
        <thead class="text-xs uppercase tracking-wide text-gray-500">
            <tr><th class="text-left p-3">Name</th><th class="text-left p-3">Email</th>
                <th class="text-left p-3">Invited</th><th class="text-left p-3">Opened</th>
                <th class="text-left p-3">Their link</th><th class="p-3"></th></tr>
        </thead>
        <tbody>
            @foreach ($invited as $inv)
                <tr class="border-t border-gray-100 dark:border-white/5">
                    <td class="p-3">{{ $inv->name }}</td>
                    <td class="p-3">{{ $inv->email }}</td>
                    <td class="p-3">{{ $inv->invited_at?->diffForHumans() }}</td>
                    <td class="p-3">{{ $inv->opened_at ? $inv->opened_at->diffForHumans() : 'Not yet' }}</td>
                    <td class="p-3"><code class="text-xs">{{ $inv->portalUrl() }}</code></td>
                    <td class="p-3 text-right">
                        @if ($confirmDeleteId === $inv->id)
                            <span class="text-xs text-gray-500 mr-2">Remove {{ $inv->name }}?</span>
                            <x-filament::button size="xs" color="danger" wire:click="deleteInvite({{ $inv->id }})">Remove</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="cancelDelete">Keep</x-filament::button>
                        @else
                            <x-filament::button size="xs" color="gray" wire:click="askDelete({{ $inv->id }})">Remove</x-filament::button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="p-3 text-xs text-gray-500">
        Nobody here has committed anything — these rows are not counted in the totals above. Removing one
        deletes the record and its link outright, which is only offered while there is nothing to keep.
    </p>
</div>
@endif

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
                    <a class="font-medium hover:underline"
                       href="{{ \App\Filament\Pages\InvestorRecord::getUrl() }}?investor={{ $investor->id }}">{{ $investor->name }}</a>
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


<!-- MARKER-RAISE-SETUP -->
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Round settings</div>
    <p class="text-sm text-gray-500">Cap, target, wire details, documents and message wording live on
        <a href="{{ \App\Filament\Pages\RaiseSetup::getUrl() }}" class="underline">Raise setup</a>.</p>
</div>

</x-filament-panels::page>
