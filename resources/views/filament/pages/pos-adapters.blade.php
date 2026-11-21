<x-filament-panels::page>
    {{-- MARKER-POS-CONNECT --}}
    <div style="font-size:13px;opacity:.8;line-height:1.55;max-width:80ch">
        <b>What this page controls:</b> Intake's own app credentials for each POS. Each shop then connects its own
        POS account from their <b>Connected POS</b> section; shops never see or enter these values. A connection
        reads the shop's catalog. Items come into Intake only when the shop adds them from Inventory › Import; linked items keep price and stock in step, and only what the shop turns on is sent back. ("Connected POS" is the shop's outside POS, not the Intake Register.)
    </div>

    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top:16px">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
