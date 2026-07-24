<x-filament-panels::page>

    {{-- MARKER-PLATFORM-MAIL --}}
    @php
        $effective    = \App\Models\PlatformSettings::fromAddress();
        $effectiveNm  = \App\Models\PlatformSettings::fromName();
        $isPlaceholder = \App\Models\PlatformSettings::isPlaceholder();
    @endphp

    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
                background: {{ $isPlaceholder ? '#FAEEDA' : '#E1F5EE' }};
                border: 1px solid {{ $isPlaceholder ? '#FAC775' : '#9FE1CB' }};
                color: {{ $isPlaceholder ? '#633806' : '#085041' }};">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">
            @if($isPlaceholder)
                ⚠ No platform sender configured — mail is going out as hello@example.com
            @else
                ● Sending as {{ $effectiveNm ? $effectiveNm . ' <' . $effective . '>' : $effective }}
            @endif
        </div>
        <div style="font-size: 12px;">
            @if($isPlaceholder)
                Laravel falls back to its framework default when no sender is set here or in the environment.
            @else
                Applies to platform mail that does not set its own sender. Tenant email is unaffected.
            @endif
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px; display: flex; gap: 8px;">
            <x-filament::button type="submit">
                Save sender
            </x-filament::button>

            <x-filament::button
                wire:click="sendTest"
                color="gray"
                icon="heroicon-o-paper-airplane">
                Save and send test
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
