<x-filament-panels::page>
    {{-- MARKER-POS-CONNECT --}}
    <div style="font-size:13px;opacity:.8;line-height:1.55;max-width:80ch">
        <b>What this page controls:</b> Intake's own app credentials for each POS. Each shop then connects its own
        POS account from <b>E-commerce → Shop POS</b>; shops never see or enter these values. Today a connection reads
        the shop's account, locations and payment types only — nothing is synced or written to any POS yet.
    </div>

    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top:16px">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
