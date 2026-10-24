{{-- MARKER-DASH-TEMPLATES: draws a template's rows of blocks. Each block is
     its own partial and gets the same data the page has. --}}
@foreach ($dashRows as $dashRow)
  @foreach ($dashRow['cols'] as $dashCol)
    @foreach ($dashCol as $dashBlock)
      @php $dashView = \App\Support\Dashboard\BlockRegistry::view($dashBlock['b']); @endphp
      @if ($dashView)
        @include($dashView)
      @endif
    @endforeach
  @endforeach
@endforeach
