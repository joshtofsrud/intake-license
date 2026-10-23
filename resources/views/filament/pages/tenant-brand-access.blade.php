<x-filament-panels::page>

  {{-- legend: what this screen does and does not touch --}}
  <div class="rounded-lg bg-primary-500/10 ring-1 ring-primary-500/30 px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
    <b>What this does.</b> Chooses which of a distributor's brands one shop is offered on its Import screen.
    "Locked on" and "Locked off" override the shop's own Brands tab; "Shop decides" hands it back.
    Only shops with the distributor add-on are listed.
    <b>What it does not do.</b> It never removes, hides or deactivates an item the shop already carries.
    Anything in their inventory, including everything in stock, stays and can still be sold.
  </div>

  <div class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-xs font-semibold text-gray-500 mb-1">Shop</label>
      <select wire:model.live="tenantId" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm">
        <option value="">Choose a shop</option>
        @foreach ($this->tenantOptions as $id => $name)
          <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-500 mb-1">Distributor</label>
      <select wire:model.live="code" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm">
        @foreach ($this->distributorOptions as $c => $label)
          <option value="{{ $c }}">{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-500 mb-1">Find a brand</label>
      <input type="search" wire:model.live.debounce.300ms="find"
             class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm" placeholder="Brand name">
    </div>
  </div>

  @if (! $tenantId)
    <p class="text-sm text-gray-500">Choose a shop to see its brands.</p>
  @else
    @php $brands = $this->brands; @endphp

    @if (count($brands) === 0)
      <p class="text-sm text-gray-500">No brands to show for that shop and distributor.</p>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
              <th class="py-2 pr-4">Brand</th>
              <th class="py-2 pr-4">Items</th>
              <th class="py-2 pr-4">Shop's own setting</th>
              <th class="py-2">Admin control</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($brands as $b)
              <tr class="border-t border-gray-200 dark:border-white/10">
                <td class="py-2 pr-4 font-semibold">{{ $b['name'] }}</td>
                <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ number_format($b['items']) }}</td>
                <td class="py-2 pr-4 text-xs text-gray-500">
                  @if ($b['locked']) set by you @else {{ $b['enabled'] ? 'on' : 'off' }} @endif
                </td>
                <td class="py-2">
                  <select wire:model="choices.{{ \App\Filament\Pages\TenantBrandAccess::h($b['key']) }}"
                          class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-xs">
                    <option value="shop">Shop decides</option>
                    <option value="on">Locked on</option>
                    <option value="off">Locked off</option>
                  </select>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div>
        <x-filament::button wire:click="save">Save brand access</x-filament::button>
      </div>
    @endif
  @endif

</x-filament-panels::page>
