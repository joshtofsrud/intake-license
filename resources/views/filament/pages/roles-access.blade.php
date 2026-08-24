<x-filament-panels::page>
<!-- MARKER-ADMIN-NAV-GATE -->

<x-filament::section>
  <x-slot name="heading">Fixed per role</x-slot>
  <x-slot name="description">This page is the reference, not an editor — there is no custom permission builder in v1. Your role is highlighted. Agency owners and reps never see master admin; their world is the /rep panel.</x-slot>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-xs uppercase tracking-wide text-gray-500">
          <th class="py-2 pr-3 text-left">Area</th>
          @foreach(array_keys($matrix) as $role)
            <th class="px-3 py-2 text-center {{ $role === $myRole ? 'text-primary-500' : '' }}">{{ ucfirst($role) }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($areas as $key => $label)
          <tr class="border-t border-gray-200 dark:border-white/10">
            <td class="py-2 pr-3">{{ $label }}</td>
            @foreach(array_keys($matrix) as $role)
              @php $lv = $matrix[$role][$key] ?? null; @endphp
              <td class="px-3 py-2 text-center {{ $role === $myRole ? 'bg-primary-500/5' : '' }}">
                @if($lv === 'full')
                  <span class="font-bold text-lime-600 dark:text-lime-300">●</span>
                @elseif($lv === 'view')
                  <span class="text-sky-600 dark:text-sky-300">◐</span>
                @else
                  <span class="text-gray-300 dark:text-gray-700">—</span>
                @endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-3 flex gap-5 text-xs text-gray-500">
    <span><span class="font-bold text-lime-600 dark:text-lime-300">●</span> full</span>
    <span><span class="text-sky-600 dark:text-sky-300">◐</span> view only</span>
    <span><span class="text-gray-400">—</span> no access</span>
    <span class="ml-auto">Only Owner and Admin ever hold Stripe keys or platform settings.</span>
  </div>
</x-filament::section>

</x-filament-panels::page>
