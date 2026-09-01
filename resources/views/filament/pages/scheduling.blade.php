<x-filament-panels::page>
<!-- MARKER-SCHED-ADMIN -->
@php
    $typeColor = function ($b) {
        return match ($b->type?->slug) {
            'demo'     => '#BEF264',
            'investor' => '#7dd3fc',
            default    => $b->source_kind === 'manual' ? '#d8b4fe' : '#fdba74',
        };
    };
    $fmtRange = fn ($b) => $b->startsLocal()->format('g:i') . '–' . $b->endsLocal()->format('g:i a');
    $modeLabel = fn ($m) => match ($m) { 'meet' => 'Google Meet', 'phone' => 'Phone', 'in_person' => 'In person', default => ucfirst($m) };
@endphp

<style>
    .sch-cal { display:grid; grid-template-columns:52px repeat(7, minmax(120px,1fr)); min-width:900px; }
    .sch-day { position:relative; border-left:1px solid rgba(127,127,127,.18);
               background:repeating-linear-gradient(to bottom, transparent 0 59px, rgba(127,127,127,.18) 59px 60px); }
    .sch-ev  { position:absolute; left:3px; right:3px; border-radius:6px; padding:3px 6px; font-size:11.5px; line-height:1.25; overflow:hidden;
               cursor:pointer; background:rgba(127,127,127,.12); border:1px solid rgba(127,127,127,.25); border-left-width:3px; text-align:left; }
    .sch-ev:hover { background:rgba(127,127,127,.22); }
    .sch-ev.is-done { opacity:.55; }
    .sch-ev.is-moved { border-style:dashed; }
    .sch-now { position:absolute; left:0; right:0; height:0; border-top:2px solid #D4FF3F; z-index:2; pointer-events:none; }
    .sch-busy { position:absolute; left:3px; right:3px; border-radius:6px; border:1px solid rgba(127,127,127,.2); border-left:3px solid rgba(127,127,127,.5);
                background:repeating-linear-gradient(135deg, rgba(127,127,127,.14) 0 6px, transparent 6px 12px); pointer-events:none; } /* MARKER-SCHED-GOOGLE */
    .sch-time { height:60px; font-size:10.5px; color:rgb(107 114 128); text-align:right; padding-right:6px; transform:translateY(-6px); }
    .sch-k { display:inline-block; width:10px; height:10px; border-radius:2px; vertical-align:-1px; margin-right:5px; }
</style>

{{-- ============ toolbar ============ --}}
<div class="flex flex-wrap items-center gap-2">
    <x-filament::button color="gray" size="sm" wire:click="prevWeek">‹</x-filament::button>
    <x-filament::button color="gray" size="sm" wire:click="thisWeek">Today</x-filament::button>
    <x-filament::button color="gray" size="sm" wire:click="nextWeek">›</x-filament::button>
    <div class="ml-2 text-sm font-medium">{{ $weekLabel }}</div>
    <div class="ml-auto flex items-center gap-2">
        <select wire:model.live="filterType" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5">
            <option value="all">All types</option>
            @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
        <x-filament::button size="sm" x-on:click="$dispatch('open-modal', { id: 'booking-new' })">New appointment</x-filament::button>
    </div>
</div>

{{-- ============ week grid ============ --}}
<div class="mt-4 rounded-xl border border-gray-200 dark:border-white/10 overflow-x-auto">
    <div class="sch-cal">
        <div></div>
        @foreach($days as $d)
            <div class="px-2 pb-2 pt-3 border-l border-gray-200 dark:border-white/10 text-xs text-gray-500">
                <div class="text-base font-semibold {{ $d['isToday'] ? 'text-primary-500' : 'text-gray-900 dark:text-white' }}">{{ $d['date']->format('D j') }}</div>
                {{ $d['count'] ? $d['count'] . ' ' . ($d['count'] === 1 ? 'call' : 'calls') : 'open' }}
            </div>
        @endforeach

        <div>
            @for($h = $gridStart; $h < $gridEnd; $h++)
                <div class="sch-time">{{ \Carbon\Carbon::createFromTime($h)->format('g a') }}</div>
            @endfor
        </div>
        @foreach($days as $i => $d)
            <div class="sch-day" style="height: {{ ($gridEnd - $gridStart) * 60 }}px">
                @if($nowTop !== null && $nowDayIndex === $i)
                    <div class="sch-now" style="top: {{ $nowTop }}px"></div>
                @endif
                @foreach($d['busy'] ?? [] as $bz)
                    <div class="sch-busy" style="top: {{ $bz['top'] }}px; height: {{ $bz['height'] }}px" title="Busy on your Google Calendar"></div>
                @endforeach
                @foreach($d['events'] as $ev)
                    @php $b = $ev['b']; @endphp
                    <button type="button" wire:click="select({{ $b->id }})"
                            class="sch-ev {{ in_array($b->status, ['completed','no_show']) ? 'is-done' : '' }} {{ $b->status === 'rescheduled' ? 'is-moved' : '' }}"
                            style="top: {{ $ev['top'] }}px; height: {{ $ev['height'] }}px; border-left-color: {{ $typeColor($b) }}">
                        <span class="font-semibold">{{ $b->startsLocal()->format('g:i') }} {{ $b->name }}</span>
                        @if($ev['height'] >= 34)<br><span class="text-gray-500">{{ $b->type?->name ?? 'Call' }}</span>@endif
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>
</div>

<div class="mt-3 rounded-lg border border-dashed border-gray-300 dark:border-white/15 px-3 py-2 text-xs text-gray-500 space-y-1">
    <div><span class="font-medium text-gray-700 dark:text-gray-300">What prospects can see:</span> nothing on this page. Public booking pages offer open slots inside your hours only — names, notes and this calendar never leave master admin.</div>
    <div>
        <span class="sch-k" style="background:#BEF264"></span>Demo call
        <span class="sch-k ml-3" style="background:#7dd3fc"></span>Investor call
        <span class="sch-k ml-3" style="background:#fdba74"></span>Other public type
        <span class="sch-k ml-3" style="background:#d8b4fe"></span>Added by hand
        <span class="ml-3">Dashed border = they have rescheduled at least once. Faded = completed or no-show.</span>
    </div>
    <div>Times are {{ $tz }} (set on Availability). Reminders go out automatically per booking type.
        @if($googleOn)<span class="sch-k" style="background:repeating-linear-gradient(135deg,#555 0 3px,transparent 3px 6px);border:1px solid #555"></span>Hatched = busy on your Google Calendar (blocks public slots; not shown to anyone else; titles never leave Google).@else Google Calendar is not connected — connect it on Availability to block slots around your other commitments.@endif
    </div>
</div>

{{-- ============ list ============ --}}
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="flex items-center justify-between mb-3">
        <div class="text-xs uppercase tracking-wide text-gray-500">
            {{ match($listRange) { 'past' => 'Past 30', 'cancelled' => 'Cancelled', default => 'Upcoming — next 14 days' } }}
        </div>
        <select wire:model.live="listRange" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5">
            <option value="upcoming">Next 14 days</option>
            <option value="past">Past</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    @if($upcoming->isEmpty())
        <div class="text-sm text-gray-500">Nothing here. Calls booked from a public page or added by hand will show up in this list.</div>
    @else
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-white/10">
                <th class="py-2 pr-3">When</th><th class="py-2 pr-3">Who</th><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Booked from</th><th class="py-2 pr-3">Status</th><th></th>
            </tr></thead>
            <tbody>
            @foreach($upcoming as $b)
                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer" wire:click="select({{ $b->id }})">
                    <td class="py-2 pr-3 whitespace-nowrap">{{ $b->startsLocal()->isToday() ? 'Today' : $b->startsLocal()->format('D M j') }} {{ $b->startsLocal()->format('g:i a') }}<div class="text-xs text-gray-500">{{ (int) abs($b->starts_at->diffInMinutes($b->ends_at)) }} min</div></td>
                    <td class="py-2 pr-3"><div class="font-medium">{{ $b->name }}</div><div class="text-xs text-gray-500">{{ $b->company ?: $b->email }}</div></td>
                    <td class="py-2 pr-3"><span class="inline-block rounded-md border border-gray-200 dark:border-white/10 px-2 py-0.5 text-xs font-medium" style="color: {{ $typeColor($b) }}">{{ $b->type?->name ?? 'Call' }}</span></td>
                    <td class="py-2 pr-3 text-gray-500 text-xs">{{ $b->source_kind === 'manual' ? 'Added by ' . ($b->creator?->name ?? 'staff') : ($b->source_url ? preg_replace('#^https?://#', '', $b->source_url) : 'public page') }}<br>{{ $b->created_at->setTimezone($tz)->format('M j, g:i a') }}</td>
                    <td class="py-2 pr-3"><span class="inline-block rounded-md border border-gray-200 dark:border-white/10 px-2 py-0.5 text-xs {{ $b->status === 'rescheduled' ? 'text-amber-500' : ($b->status === 'cancelled' ? 'text-danger-500' : '') }}">{{ $b->statusLabel() }}</span></td>
                    <td class="py-2 text-right">@if($b->location_detail && str_starts_with($b->location_detail, 'http'))<a class="text-xs underline" target="_blank" rel="noopener" href="{{ $b->location_detail }}" wire:click.stop>Join</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ============ detail modal ============ --}}
<x-filament::modal id="booking-detail" width="2xl">
    @if($booking)
        <x-slot name="heading">{{ $booking->name }}</x-slot>
        <x-slot name="description">{{ $booking->type?->name ?? 'Call' }} · {{ $booking->statusLabel() }}</x-slot>

        <div class="grid gap-x-4 gap-y-1.5 text-sm" style="grid-template-columns: 120px 1fr">
            <div class="text-gray-500">When</div>
            <div>{{ $booking->startsLocal()->format('l, M j') }} · {{ $fmtRange($booking) }} {{ $tz }}
                @if($booking->timezone && $booking->timezone !== $tz)
                    <div class="text-xs text-gray-500">{{ $booking->startsForBooker()->format('g:i a') }} their time ({{ $booking->timezone }})</div>
                @endif
            </div>
            <div class="text-gray-500">Where</div>
            <div>{{ $modeLabel($booking->location_mode) }}@if($booking->location_detail) · @if(str_starts_with($booking->location_detail, 'http'))<a class="underline" target="_blank" rel="noopener" href="{{ $booking->location_detail }}">{{ $booking->location_detail }}</a>@else{{ $booking->location_detail }}@endif @endif</div>
            <div class="text-gray-500">Contact</div>
            <div>{{ $booking->email ?: '—' }}@if($booking->phone) · {{ $booking->phone }}@endif</div>
            @if($booking->company)<div class="text-gray-500">Business</div><div>{{ $booking->company }}</div>@endif
            <div class="text-gray-500">Booked</div>
            <div>{{ $booking->source_kind === 'manual' ? 'Added by ' . ($booking->creator?->name ?? 'staff') : ('from ' . ($booking->source_url ?: 'a public page')) }} · {{ $booking->created_at->setTimezone($tz)->format('M j, g:i a') }}</div>
        </div>

        @if(!empty($booking->answers))
            <div class="mt-4 rounded-lg bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 p-3 text-sm space-y-2">
                @foreach($booking->type?->questionList() ?? [] as $q)
                    @if(isset($booking->answers[$q['key']]) && $booking->answers[$q['key']] !== '')
                        <div><div class="text-xs text-gray-500">{{ $q['label'] }}</div>{{ $booking->answers[$q['key']] }}</div>
                    @endif
                @endforeach
            </div>
        @endif

        @if($booking->message_to_them)
            <div class="mt-3 text-xs text-gray-500">Note sent with the confirmation: <span class="text-gray-700 dark:text-gray-300">{{ $booking->message_to_them }}</span></div>
        @endif

        <label class="block mt-4"><span class="text-xs text-gray-500">Your notes (never shown to them)</span>
            <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></textarea></label>
        <div class="mt-2"><x-filament::button size="sm" color="gray" wire:click="saveNotes">Save notes</x-filament::button></div>

        <div class="mt-4 text-xs text-gray-500 space-y-0.5">
            @foreach($booking->events as $ev)
                <div>{{ $ev->label() }} · {{ $ev->created_at->setTimezone($tz)->format('M j, g:i a') }}@if($ev->kind === 'rescheduled' && isset($ev->meta['from'])) · was {{ \Carbon\Carbon::parse($ev->meta['from'])->setTimezone($tz)->format('M j g:i a') }}@endif</div>
            @endforeach
        </div>

        <x-slot name="footerActions">
            @if($booking->isActive())
                <x-filament::button color="gray" wire:click="markNoShow">No-show</x-filament::button>
                <x-filament::button color="gray" wire:click="markCompleted">Completed</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('open-modal', { id: 'booking-reschedule' })">Reschedule</x-filament::button>
                <x-filament::button color="danger" x-on:click="$dispatch('open-modal', { id: 'booking-cancel' })">Cancel call</x-filament::button>
            @else
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'booking-detail' })">Close</x-filament::button>
            @endif
        </x-slot>
    @endif
</x-filament::modal>

{{-- ============ cancel modal ============ --}}
<x-filament::modal id="booking-cancel" width="md">
    <x-slot name="heading">Cancel the call with {{ $booking?->name }}?</x-slot>
    <x-slot name="description">The slot opens up again on the booking page.</x-slot>
    <label class="block"><span class="text-xs text-gray-500">Reason (kept on the record)</span>
        <textarea wire:model="cancelMessage" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></textarea></label>
    <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="cancelNotify" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">Email them that it's cancelled (includes the reason above)</label>
    <x-slot name="footerActions">
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'booking-cancel' })">Keep it</x-filament::button>
        <x-filament::button color="danger" wire:click="cancelBooking">Cancel call</x-filament::button>
    </x-slot>
</x-filament::modal>

{{-- ============ reschedule modal ============ --}}
<x-filament::modal id="booking-reschedule" width="md">
    <x-slot name="heading">Reschedule {{ $booking?->name }}</x-slot>
    <div class="grid gap-3 md:grid-cols-2">
        <label class="block"><span class="text-xs text-gray-500">New date</span>
            <input type="date" wire:model="rsDate" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        <label class="block"><span class="text-xs text-gray-500">New time ({{ $tz }})</span>
            <input type="time" wire:model="rsTime" step="300" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
    </div>
    @error('rsDate')<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
    @error('rsTime')<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
    <p class="mt-2 text-xs text-gray-500">Moves the call and keeps its length. Manual moves ignore your public hours and buffers.</p>
    <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="rsNotify" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">Email them the new time</label>
    <x-slot name="footerActions">
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'booking-reschedule' })">Back</x-filament::button>
        <x-filament::button wire:click="rescheduleBooking">Move it</x-filament::button>
    </x-slot>
</x-filament::modal>

{{-- ============ new appointment modal ============ --}}
<x-filament::modal id="booking-new" width="lg">
    <x-slot name="heading">New appointment</x-slot>
    <x-slot name="description">For a call you've agreed by email or phone. Ignores your public hours and buffers.</x-slot>
    <div class="grid gap-3">
        <label class="block"><span class="text-xs text-gray-500">Type</span>
            <select wire:model.live="nType" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ $t->length_min }} min</option>@endforeach
            </select></label>
        <div class="grid gap-3 md:grid-cols-2">
            <label class="block"><span class="text-xs text-gray-500">Name</span>
                <input wire:model="nName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            <label class="block"><span class="text-xs text-gray-500">Business</span>
                <input wire:model="nCompany" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            <label class="block"><span class="text-xs text-gray-500">Email</span>
                <input wire:model="nEmail" type="email" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            <label class="block"><span class="text-xs text-gray-500">Phone</span>
                <input wire:model="nPhone" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        </div>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="block"><span class="text-xs text-gray-500">Date</span>
                <input type="date" wire:model="nDate" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            <label class="block"><span class="text-xs text-gray-500">Time ({{ $tz }})</span>
                <input type="time" wire:model="nTime" step="300" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            <label class="block"><span class="text-xs text-gray-500">Length (min)</span>
                <input type="number" wire:model="nLength" min="5" max="480" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <label class="block"><span class="text-xs text-gray-500">Where</span>
                <select wire:model.live="nMode" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                    <option value="meet">Google Meet</option><option value="phone">Phone — I'll call them</option><option value="in_person">In person</option>
                </select></label>
            <label class="block"><span class="text-xs text-gray-500">{{ $nMode === 'meet' ? 'Meet link (blank = the type\'s link)' : ($nMode === 'phone' ? 'Number to call' : 'Address') }}</span>
                <input wire:model="nDetail" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        </div>
        <label class="block"><span class="text-xs text-gray-500">Note to them (goes in the confirmation email)</span>
            <textarea wire:model="nMessage" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></textarea></label>
        <label class="block"><span class="text-xs text-gray-500">Your notes (never shown to them)</span>
            <textarea wire:model="nNotes" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></textarea></label>
    </div>
    <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="nNotify" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">Email them a confirmation (needs an email address)</label>
    @if($errors->any())
        <div class="mt-3 text-sm text-danger-600">{{ $errors->first() }}</div>
    @endif
    <x-slot name="footerActions">
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'booking-new' })">Discard</x-filament::button>
        <x-filament::button wire:click="createManual">Save appointment</x-filament::button>
    </x-slot>
</x-filament::modal>

</x-filament-panels::page>
