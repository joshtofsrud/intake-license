{{-- Block: this week (Brief). Day strip, then each day's timeline; free time links to a new appointment. --}}
@php
  $days = $week['days'];
  $sel = \App\Support\Dashboard\BriefView::firstBusyDay($days);
  $H0 = $week['start_hour']; $H1 = $week['end_hour']; $HR = 44;
  $fmt = fn (float $h): string => \App\Support\Dashboard\BriefView::hour($h);
  $newUrl = route('tenant.appointments.index', ['new' => 1]);
@endphp
      <div class="mb-h2row"><h2 class="mb-h2">{{ $dashBlock['title'] ?? 'This week' }}</h2><span class="meta">{{ $week['total'] }} booked</span></div>

      <div class="mb-days" role="tablist">
        @foreach ($days as $i => $d)
          <button type="button" class="mb-day {{ $i === $sel ? 'on' : '' }} {{ $d['closed'] ? 'off' : '' }}" data-mb-day="{{ $i }}" role="tab">
            <div class="w">{{ $d['dow'] }}</div><div class="d">{{ $d['num'] }}</div>
            <div class="dots">@if ($d['closed'])closed @else @for ($k = 0; $k < min(4, count($d['bookings']) + count($d['untimed'])); $k++)<i></i>@endfor @endif</div>
          </button>
        @endforeach
      </div>

      @foreach ($days as $i => $d)
        @php $n = count($d['bookings']) + count($d['untimed']); @endphp
        <div class="mb-daypanel" data-mb-panel="{{ $i }}" @if ($i !== $sel) hidden @endif>
          <div class="mb-dayhead"><b>{{ $d['name'] }}</b><span>{{ $d['closed'] ? 'Closed' : ($n ? $n . ' booked' : '') }}</span></div>
          @if ($d['untimed'])
            <div class="mb-untimed">No time set:
              @foreach ($d['untimed'] as $u)<a href="{{ $u['link'] }}">{{ $u['who'] }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
            </div>
          @endif
          <div class="mb-tl" style="--mb-hr:{{ $HR }}px">
            @for ($h = $H0; $h < $H1; $h++)<div class="hr"><span>{{ $fmt($h) }}</span></div>@endfor
            <div class="lane">
              @if ($d['closed'])
                <div class="closed">Closed</div>
              @else
                @php
                  $t = max($H0, $d['open'] ?? $H0);
                  $dayEnd = min($H1, $d['close'] ?? $H1);
                  $gaps = [];
                  foreach ($d['bookings'] as $b) { if ($b['at'] - $t >= 1) $gaps[] = [$t, $b['at']]; $t = max($t, $b['at'] + $b['len']); }
                  if ($dayEnd - $t >= 1) $gaps[] = [$t, $dayEnd];
                @endphp
                @foreach ($gaps as [$g0, $g1])
                  <a class="open" href="{{ $newUrl }}" style="top:{{ ($g0 - $H0) * $HR + 3 }}px;height:{{ ($g1 - $g0) * $HR - 6 }}px"><span>Open · {{ $fmt($g0) }}–{{ $fmt($g1) }}</span><b>+ Book</b></a>
                @endforeach
                @foreach ($d['bookings'] as $b)
                  <a class="bk {{ $b['awaiting'] ? 'pend' : 'ok' }}" href="{{ $b['link'] }}" style="top:{{ ($b['at'] - $H0) * $HR + 2 }}px;height:{{ max(28, $b['len'] * $HR - 4) }}px">
                    <div><span class="who">{{ $b['who'] }}</span><span class="tm">{{ $fmt($b['at']) }}–{{ $fmt($b['at'] + $b['len']) }} · {{ $b['what'] }}</span></div>
                    <div class="side">{{ $b['awaiting'] ? 'Confirm →' : 'Confirmed' }}</div>
                  </a>
                @endforeach
              @endif
              @if ($d['is_today'] && $week['now_hour'] >= $H0 && $week['now_hour'] <= $H1)
                <div class="now" style="top:{{ ($week['now_hour'] - $H0) * $HR }}px"><span>Now {{ $fmt($week['now_hour']) }}</span></div>
              @endif
            </div>
          </div>
        </div>
      @endforeach
      <div class="mb-legend"><span><i class="a"></i>Confirmed</span><span><i class="b"></i>Waiting on you</span><span><i class="c"></i>Open: click to book</span></div>
<script>
  document.querySelectorAll('[data-mb-day]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-mb-day]').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('[data-mb-panel]').forEach(function (p) { p.hidden = p.dataset.mbPanel !== b.dataset.mbDay; });
    });
  });
</script>
