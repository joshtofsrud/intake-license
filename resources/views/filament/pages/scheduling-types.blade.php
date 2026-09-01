<x-filament-panels::page>
<!-- MARKER-SCHED-ADMIN -->

<div class="flex items-start justify-between gap-4">
    <p class="text-sm text-gray-500">Each type has its own public link, length and questions. Turning one off keeps the link but shows "not taking bookings right now". Internal types have no link — you add them by hand on the Calendar.</p>
    <x-filament::button wire:click="add">Add booking type</x-filament::button>
</div>

<div class="mt-4 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-200 dark:divide-white/10">
    @foreach($types as $t)
        <div class="p-4 grid gap-3 md:grid-cols-[1fr_auto] items-start {{ $t->is_active ? '' : 'opacity-60' }}">
            <div>
                <div class="flex items-center gap-2 text-base font-semibold">
                    {{ $t->name }}
                    <span class="rounded-md border border-gray-200 dark:border-white/10 px-2 py-0.5 text-xs font-medium text-gray-500">{{ $t->kind === 'public' ? 'public' : 'not public' }}</span>
                    @unless($t->is_active)<span class="rounded-md border border-gray-200 dark:border-white/10 px-2 py-0.5 text-xs text-gray-500">off</span>@endunless
                </div>
                <div class="mt-1 text-sm text-gray-500 space-y-0.5">
                    <div>{{ $t->length_min }} minutes · {{ $modes[$t->location_mode] ?? $t->location_mode }} · {{ $t->reminder_minutes ? 'reminder ' . ($t->reminder_minutes >= 60 ? intdiv($t->reminder_minutes, 60) . ' h' : $t->reminder_minutes . ' min') . ' before' : 'no reminder' }}</div>
                    @if($t->isPublic())
                        <div x-data="{ copied: false }" class="flex items-center gap-2">
                            <span>Public link <code class="rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 text-xs">{{ preg_replace('#^https?://#', '', $t->publicUrl()) }}</code></span>
                            <button type="button" class="text-xs underline" x-on:click="navigator.clipboard.writeText(@js($t->publicUrl())); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'Copied' : 'Copy'"></button>
                        </div>
                    @endif
                    @if($t->questionList())
                        <div>Asks: {{ collect($t->questionList())->pluck('label')->join(' · ') }}</div>
                    @else
                        <div>Asks: nothing beyond name and email</div>
                    @endif
                    <div class="text-xs">{{ $t->bookings_count }} booked · {{ $t->no_show_count }} no-show</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-filament::button size="sm" color="gray" wire:click="toggle({{ $t->id }})">{{ $t->is_active ? 'Turn off' : 'Turn on' }}</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="edit({{ $t->id }})">Edit</x-filament::button>
            </div>
        </div>
    @endforeach
</div>

<div class="mt-3 rounded-lg border border-dashed border-gray-300 dark:border-white/15 px-3 py-2 text-xs text-gray-500">
    Public links are live. A type that is off shows "not taking bookings right now" at its link instead of a calendar. Who they're meeting (name + title) is set on Availability.
</div>

<x-filament::modal id="type-edit" width="2xl">
    <x-slot name="heading">{{ $editing ? 'Edit booking type' : 'Add booking type' }}</x-slot>
    <x-slot name="description">Changes apply to new bookings only.</x-slot>

    <div class="grid gap-3 md:grid-cols-2">
        <label class="block"><span class="text-xs text-gray-500">Name</span>
            <input wire:model.live.debounce.300ms="eName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        <label class="block"><span class="text-xs text-gray-500">Link — /book/<b>slug</b></span>
            <input wire:model="eSlug" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm font-mono"></label>
        <label class="block"><span class="text-xs text-gray-500">Who can book it</span>
            <select wire:model="eKind" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                <option value="public">Anyone with the link</option>
                <option value="internal">Only you, from the Calendar</option>
            </select></label>
        <label class="block"><span class="text-xs text-gray-500">Length (minutes)</span>
            <input type="number" min="5" max="480" wire:model="eLength" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        <label class="block"><span class="text-xs text-gray-500">Where</span>
            <select wire:model.live="eMode" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                @foreach($modes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select></label>
        <label class="block"><span class="text-xs text-gray-500">Meet link @if($eMode === 'phone')(unused for phone)@endif</span>
            <input wire:model="eMeetUrl" placeholder="https://meet.google.com/…" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></label>
        <label class="block md:col-span-2"><span class="text-xs text-gray-500">Description shown on the booking page</span>
            <textarea wire:model="eDescription" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"></textarea></label>
        <label class="block"><span class="text-xs text-gray-500">Reminder</span>
            <select wire:model="eReminder" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                <option value="60">1 hour before</option><option value="1440">24 hours before</option><option value="30">30 minutes before</option><option value="0">None</option>
            </select></label>
    </div>

    <div class="mt-4">
        <div class="text-xs text-gray-500 mb-2">Questions asked when booking (name and email are always asked)</div>
        <div class="space-y-2">
            @foreach($eQuestions as $i => $q)
                <div class="grid gap-2 items-center rounded-lg border border-gray-200 dark:border-white/10 p-2" style="grid-template-columns: 1fr 120px auto auto">
                    <input wire:model="eQuestions.{{ $i }}.label" placeholder="Question" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5">
                    <select wire:model.live="eQuestions.{{ $i }}.type" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5">
                        <option value="text">Short answer</option><option value="textarea">Paragraph</option><option value="select">Pick one</option>
                    </select>
                    <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" wire:model="eQuestions.{{ $i }}.required" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">Required</label>
                    <button type="button" class="text-xs text-gray-500 underline" wire:click="removeQuestion({{ $i }})">Remove</button>
                    @if(($q['type'] ?? '') === 'select')
                        <input wire:model="eQuestions.{{ $i }}.options" placeholder="Options, comma separated" class="col-span-4 rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm py-1.5">
                    @endif
                </div>
            @endforeach
        </div>
        <x-filament::button size="xs" color="gray" class="mt-2" wire:click="addQuestion">Add question</x-filament::button>
    </div>

    @if($errors->any())
        <div class="mt-3 text-sm text-danger-600">{{ $errors->first() }}</div>
    @endif

    <x-slot name="footerActions">
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'type-edit' })">Discard</x-filament::button>
        <x-filament::button wire:click="save">Save booking type</x-filament::button>
    </x-slot>
</x-filament::modal>
</x-filament-panels::page>
