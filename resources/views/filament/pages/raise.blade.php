<x-filament-panels::page>

{{-- MARKER-RAISE-HEIGHT --}}
<style>
  .rz-invite > summary::-webkit-details-marker{display:none}
  .rz-invite[open] > summary .rz-mark::before{content:"\2013"}
  .rz-invite:not([open]) > summary .rz-mark::before{content:"+"}
  .rz-invite > summary .rz-mark::before{font-size:19px}
</style>
<!-- MARKER-RAISE-ADMIN -->

@php
    $pct = fn ($n) => $cap > 0 ? number_format($n / $cap * 100, 2) . '%' : '0%';
    $usd = fn ($n) => '$' . number_format($n);
    $progress = $target > 0 ? min(100, round($committed / $target * 100)) : 0;
@endphp

{{-- MARKER-RAISE-HEIGHT — inline grid: md:grid-cols-4 is not in this
     panel's compiled CSS and rendered as a stack. --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
    <div class="rounded-xl border border-gray-200 dark:border-white/10" style="padding:14px 16px">
        <div class="text-2xl font-bold">{{ $usd($committed) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Committed of {{ $usd($target) }}</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10" style="padding:14px 16px">
        <div class="text-2xl font-bold">{{ $usd($received) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Funds received</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10" style="padding:14px 16px">
        <div class="text-2xl font-bold">{{ $pct($committed) }}</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Sold at {{ $usd($cap) }} cap</div>
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-white/10" style="padding:14px 16px">
        <div class="text-2xl font-bold">{{ $progress }}%</div>
        <div class="text-xs uppercase tracking-wide text-gray-500">Of target</div>
    </div>
</div>

<!-- MARKER-RAISE-INVITE · MARKER-RAISE-COMPOSE-UI · MARKER-RAISE-COMPOSE-FIX · MARKER-RAISE-PANEL · MARKER-RAISE-PANEL-PAD
     Stock Tailwind utilities only in this file. Filament's CSS is
     precompiled, so arbitrary values like grid-cols-[1fr,1fr,auto] are
     never generated and fail silently — use a style attribute instead.
     px-5 and py-5 did not apply here either, so band padding and the
     inset dividers are inline. Do not "tidy" them back into classes
     without checking they render.

     Five sibling bands, not nested ones: header, message, one person, a list,
     the note. Nesting a band inside another gave it the wrong tint and a
     divider that stopped short of the panel edge. -->
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">

    {{-- MARKER-RAISE-HEIGHT — closed unless you are actually sending, or a
         preview is waiting to be confirmed. --}}
    <details class="rz-invite" @if ($invitePreview || $showPreview) open @endif>
      <summary style="padding:18px 22px;cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px">
        <span>
          <span class="text-xs uppercase tracking-wide text-gray-500">Invite someone</span>
          <span class="block text-sm text-gray-500 mt-1">Write the email once, then send it to one
            person or a list. Everyone gets their own link.</span>
        </span>
        {{-- MARKER-RAISE-MARKER — empty on purpose: the character comes from
             ::before so it can flip on open. A literal here shows twice. --}}
        <span style="margin-left:auto;color:#BEF264;line-height:1" class="rz-mark"></span>
      </summary>
      <div style="border-top:1px solid rgba(255,255,255,.08)">

    <div style="padding:20px 22px">
        <label class="block">
            <span class="text-xs font-medium text-gray-500">Subject</span>
            <input wire:model="inviteSubject"
                   class="mt-1.5 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
        </label>
        @error('inviteSubject') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror

        <label class="block mt-4">
            <span class="text-xs font-medium text-gray-500">Message</span>
            <textarea wire:model="inviteBody" rows="10"
                      style="min-height:240px;font-size:13px;line-height:1.65"
                      class="mt-1.5 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 font-mono"></textarea>
        </label>
        @error('inviteBody') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror

        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
            <span><code class="text-gray-700 dark:text-gray-300">{name}</code> their name</span>
            <span><code class="text-gray-700 dark:text-gray-300">{portal}</code> their own link</span>
            <span><code class="text-gray-700 dark:text-gray-300">{sender}</code> your sign-off</span>
            <span class="w-full">Edits apply to this send only — the saved template in Raise setup is unchanged.</span>
        </div>
    </div>

    <div style="border-top:1px solid rgba(255,255,255,.08);margin:0 22px;padding:20px 0">
        <div class="text-xs font-medium text-gray-500">Send to one person</div>
        <div class="mt-2 grid gap-3 sm:grid-cols-3">
            <input wire:model="inviteName"  placeholder="Name"
                   class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
            <input wire:model="inviteEmail" placeholder="Email"
                   class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
            <x-filament::button wire:click="inviteOne">Preview &amp; send</x-filament::button>
        </div>
        @error('inviteName')  <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
        @error('inviteEmail') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
    </div>

    <div style="border-top:1px solid rgba(255,255,255,.08);margin:0 22px;padding:20px 0">
        <div class="text-xs font-medium text-gray-500">
            Or paste a list — one per line, <code>Name &lt;email&gt;</code>
        </div>
        <textarea wire:model="inviteList" rows="4"
                  placeholder="Jane Ellery &lt;jane@example.com&gt;&#10;Marcus Hale, marcus@example.com"
                  class="mt-2 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <x-filament::button wire:click="previewList" color="gray">Preview list</x-filament::button>
            @if ($invitePreview)
                <x-filament::button wire:click="sendList">
                    Preview &amp; send {{ collect($invitePreview)->whereNull('problem')->count() }}
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

    <div style="padding:16px 22px;border-top:1px solid rgba(255,255,255,.08)" class="bg-gray-50 dark:bg-white/5">
        <p class="text-xs text-gray-500 leading-relaxed">
            Everyone invited gets their <b>own</b> record and their own link, so a pasted list is a batch
            of individual emails rather than one email to a group — you can see who opened, and withdraw
            one without touching the rest. An invitation commits nobody to anything: they enter their own
            entity and amount from their link, and only then does a commitment exist.
            <b>Nothing is created or sent until you confirm the preview</b> — cancelling leaves no record
            behind.
        </p>
    </div>
      </div>
    </details>
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
                        {{-- MARKER-SHARED-COMMIT — a record made from the shared
                             link, not one you invited. Worth knowing before
                             anything is signed. --}}
                        @if ($investor->self_declared)
                            <span class="ml-2 text-xs text-gray-500">self-declared</span>
                        @endif
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
                        {{-- MARKER-SIGNING-SEND — only once there is an amount to put in
                             the document, and only until it is signed. --}}
                        @unless ($investor->signed_at)
                            @if ($investor->committed_at && $investor->amount)
                                <x-filament::button size="xs" color="gray"
                                    wire:click="sendSafe({{ $investor->id }})"
                                    wire:loading.attr="disabled">
                                    {{ $investor->safe_sent_at ? 'Resend SAFE' : 'Send SAFE' }}
                                </x-filament::button>
                            @endif
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


{{-- MARKER-RAISE-COMPOSE-UI — exactly what will hit the inbox, laid out like
     a mail client. The body sets overflow-wrap:anywhere because a 40-character
     token has no spaces in it and will otherwise run off the panel — which is
     precisely the line worth checking. --}}
@if ($showPreview)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
     style="background:rgba(0,0,0,.65);backdrop-filter:blur(3px)"
     wire:key="invite-preview">
    <div class="w-full max-w-3xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl
                border border-gray-200 dark:border-white/10 flex flex-col overflow-hidden" style="max-height:90vh">

        <div class="px-6 py-4 border-b border-gray-200 dark:border-white/10 flex items-start gap-4">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">Exactly what will be sent</div>
                <p class="mt-1 text-sm text-gray-500">Nothing exists yet — no record, no email.</p>
            </div>
            <button type="button" wire:click="cancelPreview" aria-label="Close"
                    class="ml-auto -mr-1 -mt-1 h-8 w-8 rounded-lg text-gray-400 hover:text-gray-600
                           dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/5 text-lg leading-none">&times;</button>
        </div>

        <div class="px-6 py-4 border-b border-gray-200 dark:border-white/10 space-y-2 text-sm">
            <div class="flex gap-3">
                <span class="w-16 shrink-0 text-gray-500">To</span>
                <span class="font-medium break-all">{{ $previewTo }}</span>
            </div>
            <div class="flex gap-3">
                <span class="w-16 shrink-0 text-gray-500">Subject</span>
                <span class="font-medium">{{ $previewSubject }}</span>
            </div>
            @if ($previewOthers)
                <div class="flex gap-3">
                    <span class="w-16 shrink-0 text-gray-500">Also</span>
                    <span class="text-gray-500">
                        {{ $previewOthers }} other {{ \Illuminate\Support\Str::plural('recipient', $previewOthers) }},
                        each with their own name and their own link
                    </span>
                </div>
            @endif
        </div>

        <div class="px-6 py-5 overflow-y-auto bg-gray-50 dark:bg-white/5">
            <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 p-5">
                <pre class="font-sans text-gray-800 dark:text-gray-200"
                     style="white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;margin:0;font-size:14px;line-height:1.7;max-width:68ch">{{ $previewBody }}</pre>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 dark:border-white/10 flex flex-wrap items-center gap-3">
            <x-filament::button wire:click="confirmSend">
                Send {{ $previewOthers ? $previewOthers + 1 : 1 }}
            </x-filament::button>
            <x-filament::button color="gray" wire:click="cancelPreview">Cancel</x-filament::button>
            <span class="text-xs text-gray-500 sm:ml-auto sm:text-right" style="max-width:22rem">
                The link is real and already reserved. Cancelling discards it and writes nothing.
            </span>
        </div>
    </div>
</div>
@endif

</x-filament-panels::page>
