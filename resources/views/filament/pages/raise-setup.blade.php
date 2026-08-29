<x-filament-panels::page>
<!-- MARKER-RAISE-SETUP -->

@php
    $usd = fn ($n) => '$' . number_format((int) $n);
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">The round</div>
    <div class="grid gap-3 md:grid-cols-3">
        <label class="block"><span class="text-xs text-gray-500">Round name</span>
            <input wire:model="roundName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Instrument</span>
            <input wire:model="instrument" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Status</span>
            <select wire:model="roundStatus" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                <option value="draft">Draft</option>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
            </select></label>
        <label class="block"><span class="text-xs text-gray-500">Cap (post-money)</span>
            <input wire:model="cap" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Target raise</span>
            <input wire:model="target" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Emails signed by</span>
            <input wire:model="senderName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
    </div>
    <div class="mt-3 flex items-center gap-4">
        <x-filament::button wire:click="saveRound">Save round</x-filament::button>
        <span class="text-xs text-gray-500">
            At this cap, {{ $usd($target) }} is
            {{ (int) $cap > 0 ? number_format($target / $cap * 100, 2) : '0' }}% of the company.
            {{ $usd($committed) }} committed so far.
        </span>
    </div>
    @error('cap')    <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('target') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
</div>

<!-- MARKER-INVEST-LANDING -->
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Public landing copy — /invest</div>
    <div class="grid gap-3">
        <label class="block"><span class="text-xs text-gray-500">Headline</span>
            <input wire:model="landingHeadline" placeholder="Leave blank to use the built-in line"
                   class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Opening paragraph</span>
            <textarea wire:model="landingLede" rows="3" placeholder="Leave blank to use the built-in paragraph"
                      class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>
        <label class="block"><span class="text-xs text-gray-500">Access requests emailed to</span>
            <input wire:model="notifyEmail" placeholder="falls back to the system from-address"
                   class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Fine print</span>
            <textarea wire:model="landingFine" rows="3" placeholder="Leave blank to use the built-in wording"
                      class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>
    </div>
    <!-- MARKER-INVEST-LIVE -->
    <label class="mt-3 flex items-center gap-2">
        <input type="checkbox" wire:model="showProgress" class="rounded border-gray-300 dark:bg-white/5 dark:border-white/10">
        <span class="text-sm">Show the progress bar on the gated proposal</span>
    </label>
    <p class="mt-1 text-xs text-gray-500">
        It appears behind the access code only, never on the public page, and only once something has been
        committed. Signed-and-funded and merely-committed are drawn as separate bands, because they are not
        the same thing.
    </p>

    <div class="mt-3"><x-filament::button wire:click="saveLanding">Save landing copy</x-filament::button></div>
    <p class="mt-3 text-xs text-gray-500">
        MARKER-INVEST-V2: /invest states no terms at all — not the raise, not the cap, not progress.
        Describing the company is not advertising the offering, and only the second is restricted, so
        everything about the round now lives behind the access code. These fields control the opening
        headline and paragraph; the headline accepts inline HTML so a word can be highlighted.
    </p>
    @error('notifyEmail') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Wire details and compliance</div>
    <div class="grid gap-3 md:grid-cols-4">
        <label class="block"><span class="text-xs text-gray-500">Bank</span>
            <input wire:model="wireBank" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Account</span>
            <input wire:model="wireAccount" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Routing</span>
            <input wire:model="wireRouting" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Reference</span>
            <input wire:model="wireReference" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Form D filed</span>
            <input wire:model="formDFiledAt" placeholder="YYYY-MM-DD" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block md:col-span-2"><span class="text-xs text-gray-500">Blue-sky notes</span>
            <input wire:model="blueSkyNotes" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <div class="flex items-end"><x-filament::button wire:click="saveCompliance">Save</x-filament::button></div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        Set the wire details before marking anyone signed, or that email goes out with the bank line blank.
        Form D and blue-sky rows record what you say you filed. Intake files nothing with the SEC or any state.
    </p>
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Documents on the invitation page</div>

    <div class="grid gap-3 md:grid-cols-3">
        <label class="block md:col-span-1"><span class="text-xs text-gray-500">Label</span>
            <input wire:model="uploadLabel" placeholder="Investment opportunity" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block md:col-span-1"><span class="text-xs text-gray-500">PDF</span>
            <input type="file" wire:model="upload" accept="application/pdf" class="mt-1 w-full text-sm"></label>
        <div class="flex items-end"><x-filament::button wire:click="uploadDocument">Upload</x-filament::button></div>
    </div>
    @error('upload')      <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('uploadLabel') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    <div wire:loading wire:target="upload" class="text-xs text-gray-500 mt-2">Uploading…</div>

    @if ($documents->isNotEmpty())
    <table class="w-full text-sm mt-4">
        <thead class="text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="text-left p-2">Document</th>
                <th class="text-left p-2">Link</th>
                <th class="text-right p-2">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($documents as $doc)
            <tr class="border-t border-gray-100 dark:border-white/5 {{ $doc->is_active ? '' : 'opacity-50' }}">
                <td class="p-2">
                    <div class="font-medium">{{ $doc->label }}</div>
                    <div class="text-xs text-gray-500">
                        {{ $doc->path }}
                        @unless ($doc->exists())
                            <span class="text-danger-600">— file missing on disk</span>
                        @endunless
                    </div>
                </td>
                <td class="p-2 font-mono text-xs">/invest/&lt;token&gt;/doc/{{ $doc->slug }}</td>
                <td class="p-2 text-right whitespace-nowrap">
                    <x-filament::button size="xs" color="gray" wire:click="toggleDocument({{ $doc->id }})">
                        {{ $doc->is_active ? 'Hide' : 'Show' }}
                    </x-filament::button>
                    <x-filament::button size="xs" color="danger"
                        wire:click="deleteDocument({{ $doc->id }})"
                        wire:confirm="Delete {{ $doc->label }}? The file is removed from disk.">Delete</x-filament::button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
        <p class="mt-3 text-xs text-gray-500">Nothing uploaded yet, so the invitation page has no downloads.</p>
    @endif
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Messages</div>

    @if ($templateKey)
        <div class="grid gap-3">
            <label class="block"><span class="text-xs text-gray-500">Subject</span>
                <input wire:model="templateSubject" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
            <label class="block"><span class="text-xs text-gray-500">Body</span>
                <textarea wire:model="templateBody" rows="12" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 font-mono text-xs"></textarea></label>
            <div class="flex gap-2">
                <x-filament::button wire:click="saveTemplate">Save</x-filament::button>
                <x-filament::button color="gray" wire:click="resetTemplate"
                    wire:confirm="Discard your wording and go back to the shipped version?">Reset</x-filament::button>
                <x-filament::button color="gray" wire:click="closeTemplate">Close</x-filament::button>
            </div>
            <p class="text-xs text-gray-500">
                Placeholders: &#123;name&#125; &#123;amount&#125; &#123;percent&#125; &#123;cap&#125; &#123;portal&#125;
                &#123;bank&#125; &#123;account&#125; &#123;routing&#125; &#123;reference&#125; &#123;sender&#125;
            </p>
        </div>
    @else
        <table class="w-full text-sm">
            <tbody>
            @foreach ($templates as $key => $template)
                <tr class="border-t border-gray-100 dark:border-white/5">
                    <td class="p-2">
                        <div class="font-medium">{{ $template['label'] ?? $key }}</div>
                        <div class="text-xs text-gray-500">{{ $template['subject'] ?? '' }}</div>
                    </td>
                    <td class="p-2 text-xs text-gray-500">
                        {{ ($template['auto'] ?? false) ? 'Automatic' : 'Manual' }}
                        {{ in_array($key, $overrides, true) ? ' · edited' : '' }}
                    </td>
                    <td class="p-2 text-right">
                        <x-filament::button size="xs" color="gray" wire:click="editTemplate('{{ $key }}')">Edit</x-filament::button>
                    {{-- MARKER-RAISE-HTML --}}
                    <x-filament::button size="xs" color="gray"
                        wire:click="sendTest('{{ $key }}')"
                        wire:loading.attr="disabled">Send test</x-filament::button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

</x-filament-panels::page>
