{{-- Block: the queue (Brief). Each row is a count that opens its own filtered list. --}}
@php $bq = \App\Support\Dashboard\BriefView::queue($attention ?? []); @endphp
      <div class="mb-h2row"><h2 class="mb-h2">{{ $dashBlock['title'] ?? 'The queue' }}</h2><span class="meta">Most urgent first · each row opens its list</span></div>
      @if ($bq['cards']->isEmpty())
        <div class="mb-calm">Nothing needs you right now.</div>
      @endif
      @foreach ($bq['groups'] as $gk => [$label, $rows])
        @continue($rows->isEmpty())
        @php $sum = (int) $rows->sum('count'); @endphp
        <div class="mb-grp">
          <div class="mb-grp-h {{ $gk }}"><i></i>{{ $label }}<span class="c">{{ $sum }} {{ \Illuminate\Support\Str::plural('thing', $sum) }}</span></div>
          @foreach ($rows as $c)
            <a class="mb-row" href="{{ $c['link'] }}">
              <span class="n">{{ $c['count'] }}</span>
              <span><div class="t">{{ $c['title'] }}</div><div class="s">{{ $c['desc'] }}</div></span>
              <span class="arr">›</span>
            </a>
          @endforeach
        </div>
      @endforeach
