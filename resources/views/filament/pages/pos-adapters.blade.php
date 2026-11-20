<x-filament-panels::page>
    {{-- MARKER-POS-CONNECT --}}
    <div style="font-size:13px;opacity:.8;line-height:1.55;max-width:80ch">
        <b>What this page controls:</b> Intake's own app credentials for each POS. Each shop then connects its own
        POS account from <b>E-commerce → Connected POS</b>; shops never see or enter these values. With sync on, a connection
        brings the shop's products, prices and stock into Intake; nothing is written to any POS yet. ("Connected POS" is the shop's outside POS, not the Intake Register.)
    </div>

    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top:16px">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
