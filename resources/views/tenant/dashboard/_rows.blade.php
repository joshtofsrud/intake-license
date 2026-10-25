{{-- MARKER-DASH-TEMPLATES: draws a template's rows of blocks. Each block is
     its own partial and gets the same data the page has. A full-width row is
     just its blocks; a row with columns becomes a 12-unit grid that stacks
     into one column on phones. --}}
@once
  @push('styles')
    <style>
      .dash-row { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); column-gap: 28px; align-items: start; }
      .dash-col { min-width: 0; }
      @media (max-width: 1023px) {
        .dash-row { display: block; }
        .dash-row > .dash-col + .dash-col { margin-top: 24px; }
      }
    </style>
  @endpush
@endonce
@foreach ($dashRows as $dashRow)
  @if (count($dashRow['split']) > 1)
    <div class="dash-row">
      @foreach ($dashRow['cols'] as $dashCi => $dashCol)
        <div class="dash-col" style="grid-column:span {{ $dashRow['split'][$dashCi] ?? 12 }}">
          @foreach ($dashCol as $dashBlock)
            @php $dashView = \App\Support\Dashboard\BlockRegistry::view($dashBlock['b']); @endphp
            @if ($dashView)
              @include($dashView)
            @endif
          @endforeach
        </div>
      @endforeach
    </div>
  @else
    @foreach ($dashRow['cols'] as $dashCol)
      @foreach ($dashCol as $dashBlock)
        @php $dashView = \App\Support\Dashboard\BlockRegistry::view($dashBlock['b']); @endphp
        @if ($dashView)
          @include($dashView)
        @endif
      @endforeach
    @endforeach
  @endif
@endforeach
