{{-- Block: Needs you strip (Studio). MARKER-STUDIO-NEEDS
     The same attention items as the Needs you tiles, already cut to what this
     person's role can open (BlockAccess::attention), as one row of chips under
     the greeting: red first, then amber, violet, blue. Desktop keeps one row and
     folds the rest into "+N more"; phones swipe the row sideways. --}}
@php
  $nyWeight = ['red' => 0, 'amber' => 1, 'violet' => 2, 'blue' => 3];
  $nyCards = collect($attention['cards'] ?? [])
      ->filter(fn ($c) => ($c['count'] ?? 0) > 0)
      ->sortBy(fn ($c) => $nyWeight[$c['tone'] ?? 'blue'] ?? 99)
      ->values();
  // what a chip opens, in words: the list's name, and "filtered" when the
  // link narrows it (e.g. appointments?filter=…)
  $nyOpens = function (?string $link): string {
      $path = trim((string) parse_url((string) $link, PHP_URL_PATH), '/');
      $seg = $path === '' ? '' : \Illuminate\Support\Str::afterLast($path, '/');
      $name = $seg === '' || $seg === 'admin' ? 'the list' : ucfirst(str_replace('-', ' ', $seg));
      return parse_url((string) $link, PHP_URL_QUERY) ? $name . ', filtered' : $name;
  };
@endphp
<section class="sd-ny{{ $nyCards->isEmpty() ? ' clear' : '' }}" id="sdNy" aria-label="Needs you" data-sd-live="needs">
  @if ($nyCards->isNotEmpty())
    <div class="sd-ny-h"><b>{{ $nyCards->count() }}</b>{{ $nyCards->count() === 1 ? 'needs you' : 'need you' }}</div>
    <div class="sd-ny-rail" id="sdNyRail">
      @foreach ($nyCards as $i => $c)
        <a class="sd-chip {{ in_array($c['tone'] ?? '', ['red', 'amber', 'violet', 'blue'], true) ? $c['tone'] : 'amber' }}" href="{{ $c['link'] ?? '#' }}" style="--i:{{ $i }}"
           data-desc="{{ $c['desc'] ?? '' }}" data-opens="{{ $nyOpens($c['link'] ?? null) }}"
           aria-label="{{ $c['count'] }} {{ $c['title'] ?? '' }}: {{ $c['desc'] ?? '' }}"><span class="n">{{ $c['count'] }}</span>{{ $c['title'] ?? '' }}<span class="ar" aria-hidden="true">→</span></a>
      @endforeach
      <button type="button" class="sd-chip more" data-ny-more hidden><span class="n">+0</span>more</button>
      <button type="button" class="sd-chip more less" data-ny-less hidden>Show less</button>
    </div>
  @else
    <div class="sd-ny-clear">
      <i aria-hidden="true">✓</i>Nothing needs you right now.
      @if ($see->can('schedule'))
        <a class="sd-link" href="{{ route('tenant.calendar.index') }}">Open the calendar →</a>
      @endif
    </div>
  @endif
</section>
