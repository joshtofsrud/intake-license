<x-filament-panels::page>
<!-- MARKER-RAISE-RECORDS -->

@php
    $usd = fn ($n) => '$' . number_format((int) $n);
@endphp

<div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
        <div class="text-2xl font-bold">{{ $investor->name }}</div>
        <div class="text-sm text-gray-500">
            {{ $investor->status }} · {{ $usd($investor->amount) }} · {{ $investor->percent }}% at the {{ $usd($cap) }} cap
        </div>
    </div>
    <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Pages\Raise::getUrl() }}">Back to raise</x-filament::button>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <div class="lg:col-span-2 space-y-6">

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Details</div>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block"><span class="text-xs text-gray-500">Name</span>
                    <input wire:model="name" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Email</span>
                    <input wire:model="email" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Entity</span>
                    <input wire:model="entity" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Funding method</span>
                    <input wire:model="fundingMethod" placeholder="Wire, check, ACH" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Committed</span>
                    <input wire:model="amount" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Received</span>
                    <input wire:model="amountReceived" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
            </div>
            <label class="block mt-3"><span class="text-xs text-gray-500">Notes</span>
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>
            <div class="mt-3"><x-filament::button wire:click="save">Save</x-filament::button></div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Documents</div>

            @forelse ($documents as $doc)
                <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-white/5">
                    <div>
                        <div class="font-medium">{{ $doc->label }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $doc->original_name }}
                            @if ($doc->signed_at) · signed {{ $doc->signed_at->toFormattedDateString() }} @endif
                            @unless ($doc->visible_to_investor) · hidden from portal @endunless
                        </div>
                    </div>
                    <div class="flex gap-2 whitespace-nowrap">
                        <x-filament::button size="xs" color="gray" wire:click="downloadDocument({{ $doc->id }})">Download</x-filament::button>
                        @unless ($doc->signed_at)
                            <x-filament::button size="xs" wire:click="markDocumentSigned({{ $doc->id }})">Signed</x-filament::button>
                        @endunless
                        <x-filament::button size="xs" color="gray" wire:click="toggleVisibility({{ $doc->id }})">
                            {{ $doc->visible_to_investor ? 'Hide' : 'Show' }}
                        </x-filament::button>
                        <x-filament::button size="xs" color="danger"
                            wire:click="deleteDocument({{ $doc->id }})"
                            wire:confirm="Delete {{ $doc->label }}?">Delete</x-filament::button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No documents yet.</p>
            @endforelse

            <div class="mt-4 grid gap-3 md:grid-cols-3 items-end">
                <label class="block md:col-span-1"><span class="text-xs text-gray-500">Label</span>
                    <input wire:model="docLabel" placeholder="SAFE agreement" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block md:col-span-1"><span class="text-xs text-gray-500">File</span>
                    <input type="file" wire:model="upload" class="mt-1 w-full text-sm"></label>
                <div><x-filament::button wire:click="uploadDocument">Upload</x-filament::button></div>
            </div>
            @error('upload') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
            @error('docLabel') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror

            <p class="mt-3 text-xs text-gray-500">
                Intake stores documents and records the signing date. It does not provide e-signature — sign through
                DocuSign or Dropbox Sign and upload the executed copy here.
            </p>
        </div>

    </div>

    <div class="space-y-6">

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Private portal link</div>
            <div class="font-mono text-xs break-all">{{ $investor->portalUrl() }}</div>
            <div class="text-xs text-gray-500 mt-2">
                @if ($investor->opened_at)
                    Opened {{ $investor->opened_at->diffForHumans() }}
                @else
                    Not opened yet
                @endif
            </div>
            <p class="mt-3 text-xs text-gray-500">
                This link is personal to {{ $investor->name }} and shows only their own position.
            </p>
        </div>

        <!-- MARKER-RAISE-MESSAGES -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Messages</div>

            @foreach ($templates as $key => $template)
                <div class="py-2 border-b border-gray-100 dark:border-white/5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium">{{ $template['label'] }}</div>
                            <div class="text-xs text-gray-500">{{ ucfirst($template['mode']) }} · {{ $template['trigger'] }}</div>
                        </div>
                        <x-filament::button size="xs" color="gray" wire:click="previewMessage('{{ $key }}')">
                            {{ $previewKey === $key ? 'Close' : 'Preview' }}
                        </x-filament::button>
                    </div>

                    @if ($previewKey === $key && $preview)
                        <div class="mt-2 rounded-lg bg-gray-50 dark:bg-white/5 p-3">
                            <div class="text-xs text-gray-500 mb-1">{{ $preview['subject'] }}</div>
                            <pre class="text-xs whitespace-pre-wrap font-sans">{{ $preview['body'] }}</pre>
                            <div class="mt-3">
                                <x-filament::button size="xs"
                                    wire:click="sendMessage('{{ $key }}')"
                                    wire:confirm="Send this to {{ $investor->email ?: 'nobody — no email on file' }}?">
                                    Send now
                                </x-filament::button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach

            <p class="mt-3 text-xs text-gray-500">
                Automatic messages already fire on their trigger. Sending here is for the manual ones, or for
                resending after a change.
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Activity</div>
            @forelse ($events as $event)
                <div class="py-2 border-b border-gray-100 dark:border-white/5">
                    <div class="text-sm">{{ $event->description }}</div>
                    <div class="text-xs text-gray-500">{{ $event->created_at?->diffForHumans() }}</div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing recorded yet.</p>
            @endforelse
        </div>

    </div>
</div>

</x-filament-panels::page>
