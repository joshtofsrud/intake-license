<x-filament-panels::page>
    {{-- MARKER-OPTION-SPLIT / MARKER-OPTION-FIELDS --}}
    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid rgba(127,127,127,.35); font-size: 13px; line-height: 1.55;">
        <div style="font-weight: 600; margin-bottom: 4px;">What this changes</div>
        <div>Only the register's item picker. Each option here becomes its own dropdown when it tells at least two variants of a product apart. Values are read from each distributor's own catalog fields, then their spellings are mapped to one name, so the same tire from BTI, HLC and QBP lines up.</div>
        <div style="margin-top: 6px; opacity: .8;">Not affected: item names, inventory records, search, receipts, the online store, and the distributor data itself. Catalog rows are re-read hourly and straight after you save. Items not linked to a catalog row fall back to reading their own name by the known values.</div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px;">
            <x-filament::button type="submit">
                Save options
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
