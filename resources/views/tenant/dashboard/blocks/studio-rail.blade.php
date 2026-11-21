{{-- Block: Studio calendar and today (MARKER-DASH-STUDIO). The month is shaded
     by how busy each day is: booked against the day's limit for drop-off
     shops, against the month's busiest day for time-slot shops. Below it,
     today: a timeline with Next up for time-slot shops, or how full today is
     and the drop-offs for drop-off shops. --}}
@php
  $rail = $studio['rail'] ?? null;
  $sdMode = $studio['mode'] ?? 'slots';
  $assetP = $studio['assetP'] ?? 'items';
@endphp
@if ($rail)
<aside class="sd-rail sd-in" style="--d:140ms" data-sd-live="rail">
  {{-- phone: one row of days that swipes sideways, a week showing at a time. It opens on this week, reaches back about 90 days and runs
       as far ahead as the shop takes bookings; a past day with something unfinished is red; the heading follows the month in view, and the 1st shows its month. --}}
  @php $sdStrip = $rail['strip'] ?? []; @endphp
  @if ($sdStrip)
  <div class="sd-week">
    <div class="sd-cal-h"><b data-sd-week-label>{{ $rail['month'] }}</b><a href="{{ route('tenant.calendar.index') }}" class="sd-link">Open calendar →</a></div>
    <div class="sd-week-g" data-sd-week>
      @foreach ($sdStrip as $d)
        <a href="{{ $d['url'] }}" class="{{ $d['today'] ? 't' : '' }}{{ $d['closed'] ? ' x' : '' }}{{ $d['monday'] ? ' mon' : '' }}{{ $d['past'] ? ' past' : '' }}{{ $d['open'] ? ' late' : '' }}" style="--f:{{ $d['fill'] }}" data-month="{{ $d['month'] }}"
           aria-label="{{ \Illuminate\Support\Carbon::parse($d['date'])->format('l, M j') }}: {{ $d['closed'] ? 'closed' : $d['n'] . ' ' . \Illuminate\Support\Str::plural('booking', $d['n']) }}{{ $d['open'] ? ', ' . $d['open'] . ' not finished' : '' }}"><small>{{ $d['d'] === 1 ? $d['mon'] : $d['dow'] }}</small>{{ $d['d'] }}</a>
      @endforeach
    </div>
    @if (collect($sdStrip)->contains(fn ($d) => $d['open'] > 0))
      <div class="sd-week-key"><i></i>A red day in the past still has an appointment that isn't finished. Tap it to open that day.</div>
    @endif
  </div>
  @endif
  <div class="sd-cal">
    <div class="sd-cal-h"><b>{{ $rail['month'] }}</b><a href="{{ route('tenant.calendar.index') }}" class="sd-link">Open calendar →</a></div>
    <div class="sd-cal-g">
      @foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $w)<div class="w">{{ $w }}</div>@endforeach
      @for ($i = 0; $i < $rail['lead']; $i++)<span></span>@endfor
      @foreach ($rail['days'] as $d)
        <a href="{{ $d['url'] }}" class="{{ $d['today'] ? 't' : '' }}{{ $d['closed'] ? ' x' : '' }}" style="--f:{{ $d['fill'] }}"
           title="{{ \Illuminate\Support\Carbon::parse($d['date'])->format('l, M j') }}: {{ $d['closed'] ? 'closed' : $d['n'] . ' ' . \Illuminate\Support\Str::plural('booking', $d['n']) }}">{{ $d['d'] }}</a>
      @endforeach
    </div>
    <div class="sd-cal-key"><span><i style="--f:.25"></i>Quiet</span><span><i style="--f:.6"></i>Busy</span><span><i style="--f:1"></i>{{ $sdMode === 'capacity' ? 'Full' : 'Busiest' }}</span></div>
  </div>

  @if ($sdMode === 'slots')
    @php $tl = $rail['timeline']; @endphp
    <div class="sd-tl-h"><b>Today</b><span>{{ $tl['left'] ? $tl['left'] . ' still to go' : ($tl['rows'] ? 'all done' : '') }}</span></div>
    @if (! $tl['rows'])
      <div class="sd-empty">Nothing booked today.
        <div class="sd-empty-acts">@if ($see->can('schedule'))<a class="ia-btn ia-btn--sm" href="{{ route('tenant.appointments.index', ['new' => 1]) }}">New booking</a>@endif<a class="ia-btn ia-btn--sm" href="{{ route('tenant.calendar.index') }}">See the week</a></div>
      </div>
    @else
      @php $tlDone = collect($tl['rows'])->where('state', 'done')->count(); @endphp
      @if ($tlDone && $tlDone < count($tl['rows']))<button type="button" class="sd-donebtn" data-sd-done data-n="{{ $tlDone }}" aria-expanded="false">{{ $tlDone }} done earlier today · show</button>@endif
      <div class="sd-tl">
        @foreach ($tl['rows'] as $r)
          <a class="sd-tl-r {{ $r['state'] }}" href="{{ $r['url'] }}">
            <time>{{ $r['time'] }}</time>
            <div>
              <b>@if ($r['state'] === 'next')<em>Next up</em>@endif{{ $r['title'] }}@if ($r['state'] === 'active') · in progress @endif</b>
              <span>{{ $r['who'] }}@if (count($r['assets']) > 1) · <button type="button" class="sd-multi" data-assets='@json($r['assets'])'>{{ count($r['assets']) }} {{ $assetP }}</button>@endif</span>
            </div>
          </a>
        @endforeach
      </div>
    @endif
  @else
    @php $cap = $rail['capacity']; $dp = $rail['dropoffs']; @endphp
    <div class="sd-tl-h"><b>Today</b><span>{{ $cap['max'] ? $cap['used'] . ' of ' . $cap['max'] . ' spots taken' : $cap['used'] . ' booked' }}</span></div>
    @if ($cap['max'])
      <div class="sd-capbar" aria-hidden="true"><span style="--w:{{ min(100, round($cap['used'] / max(1, $cap['max']) * 100)) }}%"></span></div>
    @endif
    @if (! $dp['rows'])
      <div class="sd-empty">No drop-offs today.
        <div class="sd-empty-acts">@if ($see->can('schedule'))<a class="ia-btn ia-btn--sm" href="{{ route('tenant.appointments.index', ['new' => 1]) }}">New booking</a>@endif<a class="ia-btn ia-btn--sm" href="{{ route('tenant.calendar.index') }}">See the week</a></div>
      </div>
    @else
      @php $tlDone = collect($dp['rows'])->where('state', 'done')->count(); @endphp
      @if ($tlDone && $tlDone < count($dp['rows']))<button type="button" class="sd-donebtn" data-sd-done data-n="{{ $tlDone }}" aria-expanded="false">{{ $tlDone }} done earlier today · show</button>@endif
      <div class="sd-tl sd-tl--drop">
        @foreach ($dp['rows'] as $r)
          <a class="sd-tl-r {{ $r['state'] }}" href="{{ $r['url'] }}">
            <time>{{ ! empty($r['route']) ? $r['time'] : ($r['state'] === 'done' ? 'Done' : ($r['state'] === 'active' ? 'Working' : ($r['state'] === 'next' ? 'Next' : ''))) }}</time>
            <div>
              <b>@if ($r['state'] === 'next')<em>Next up</em>@endif{{ $r['title'] }}</b>
              <span>{{ $r['who'] }} · {{ $r['status'] }}@if (count($r['assets']) > 1) · <button type="button" class="sd-multi" data-assets='@json($r['assets'])'>{{ count($r['assets']) }} {{ $assetP }}</button>@endif</span>
            </div>
          </a>
        @endforeach
      </div>
    @endif
  @endif
</aside>
@endif
