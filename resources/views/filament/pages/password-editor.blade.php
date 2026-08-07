<x-filament-panels::page>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px;">
            <x-filament::button type="submit">
                Set password
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
