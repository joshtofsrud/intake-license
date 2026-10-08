<x-filament-panels::page>
    {{-- MARKER-OPTION-SPLIT --}}
    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid rgba(127,127,127,.35); font-size: 13px; line-height: 1.55;">
        <div style="font-weight: 600; margin-bottom: 4px;">What this changes</div>
        <div>Only the register's item picker. When a product's variants differ by words listed here, those words get their own dropdown instead of sitting in one long Version list.</div>
        <div style="margin-top: 6px; opacity: .8;">Not affected: item names, inventory records, search, receipts, the online store. A rule only shows when it tells at least two variants apart. Items not linked to a distributor catalog have no category, so rules with a category condition skip them. Words no rule knows stay in Version, or show as "Also in the name" when they don't differ.</div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px;">
            <x-filament::button type="submit">
                Save rules
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
