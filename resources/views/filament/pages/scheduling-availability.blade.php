<x-filament-panels::page>
<!-- MARKER-SCHED-ADMIN -->
@php $days = \App\Filament\Pages\SchedulingAvailability::DAYS; $zones = \App\Filament\Pages\SchedulingAvailability::TIMEZONES; @endphp

<div class="grid gap-4 lg:grid-cols-2">
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Weekly hours</div>
        <label class="block mb-4"><span class="text-xs text-gray-500">Timezone</span>
            <select wire:model="timezone" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                @foreach($zones as $id => $label)<option value="{{ $id }}">{{ $label }} — {{ $id }}</option>@endforeach
            </select></label>
        <div class="space-y-2">
            @foreach($days as $k => $label)
                <div class="grid items-center gap-2" style="grid-template-columns: 24px 40px 1fr 16px 1fr">
                    <input type="checkbox" wire:model.live="hours.{{ $k }}.on" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">
                    <span class="text-sm">{{ $label }}</span>
                    <input type="time" step="900" wire:model="hours.{{ $k }}.from" @disabled(empty($hours[$k]['on'])) class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5 disabled:opacity-40">
                    <span class="text-center text-gray-400">–</span>
                    <input type="time" step="900" wire:model="hours.{{ $k }}.to" @disabled(empty($hours[$k]['on'])) class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5 disabled:opacity-40">
                </div>
            @endforeach
        </div>
        @error('hours.*.from')<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
        <p class="mt-3 text-xs text-gray-500">One block per day for now. A day whose end isn't after its start is treated as off.</p>

        <div class="text-xs uppercase tracking-wide text-gray-500 mt-6 mb-3">Blocked dates</div>
        @forelse($blocked as $i => $b)
            <div class="flex items-center justify-between py-1.5 text-sm border-b border-gray-100 dark:border-white/5">
                <span>{{ \Carbon\Carbon::parse($b['from'])->format('M j') }}@if(($b['to'] ?? $b['from']) !== $b['from'])–{{ \Carbon\Carbon::parse($b['to'])->format('M j') }}@endif @if(!empty($b['label']))<span class="text-gray-500">— {{ $b['label'] }}</span>@endif</span>
                <x-filament::button size="xs" color="gray" wire:click="removeBlocked({{ $i }})">Remove</x-filament::button>
            </div>
        @empty
            <div class="text-sm text-gray-500">No blocked dates.</div>
        @endforelse
        <div class="mt-3 grid gap-2 md:grid-cols-4 items-end">
            <label class="block"><span class="text-xs text-gray-500">From</span><input type="date" wire:model="newFrom" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5"></label>
            <label class="block"><span class="text-xs text-gray-500">To (optional)</span><input type="date" wire:model="newTo" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5"></label>
            <label class="block"><span class="text-xs text-gray-500">Label</span><input wire:model="newLabel" placeholder="Labor Day" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5"></label>
            <x-filament::button color="gray" wire:click="addBlocked">Block</x-filament::button>
        </div>
        @error('newFrom')<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
        @error('newTo')<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Rules</div>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block"><span class="text-xs text-gray-500">Minimum notice (hours)</span>
                    <input type="number" min="0" wire:model="minNoticeHours" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
                <label class="block"><span class="text-xs text-gray-500">Buffer before and after (minutes)</span>
                    <input type="number" min="0" wire:model="bufferMinutes" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
                <label class="block"><span class="text-xs text-gray-500">Calls per day, at most (0 = no cap)</span>
                    <input type="number" min="0" wire:model="maxPerDay" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
                <label class="block"><span class="text-xs text-gray-500">How far out people can book (weeks)</span>
                    <input type="number" min="1" max="26" wire:model="windowWeeks" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
            </div>
            @foreach(['minNoticeHours','bufferMinutes','maxPerDay','windowWeeks','timezone'] as $f)
                @error($f)<p class="text-sm text-danger-600 mt-2">{{ $message }}</p>@enderror
            @endforeach
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Google Calendar</div>
            <div class="text-sm">Not connected.</div>
            <p class="mt-1 text-xs text-gray-500">Busy time and Meet links come with the Google update. Until then, anything that should block a slot goes in as a blocked date, and Meet links are set per booking type.</p>
        </div>

        <div class="rounded-lg border border-dashed border-gray-300 dark:border-white/15 px-3 py-2 text-xs text-gray-500 space-y-1">
            <div><span class="font-medium text-gray-700 dark:text-gray-300">What prospects see:</span> open times only — never these settings, what's blocking a slot, or the labels on blocked dates.</div>
            <div><span class="font-medium text-gray-700 dark:text-gray-300">Not affected by these rules:</span> appointments you add by hand on the Calendar. Those can sit anywhere, including outside hours and inside buffers.</div>
        </div>

        <div><x-filament::button wire:click="save">Save changes</x-filament::button></div>
    </div>
</div>
</x-filament-panels::page>
