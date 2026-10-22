{{-- MARKER-STEP-TIMES: the sessions behind a Marketing traffic tile, opened by clicking it. --}}
@foreach ($labels as $label)
  @php $rowsS = $stepTimes[$label] ?? []; @endphp
  <div class="mt-steplist" data-mt-list="{{ $label }}" hidden>
    <div class="mt-steplist-h">{{ $label }} · {{ count($rowsS) >= \App\Services\Platform\MarketingStepTimes::LIMIT ? 'latest ' . count($rowsS) : count($rowsS) }} <button type="button" data-mt-close>Close ×</button></div>
    @forelse ($rowsS as $r)
      <div class="mt-steprow">
        <span class="t">{{ $r['when'] }}</span>
        @if ($r['session'] !== '')
          <span class="s">{{ $r['source'] }}</span>
          <span class="p">{{ $r['landing'] }}</span>
          @if ($r['shop'])<span class="rse-src">Email · {{ $r['shop'] }}</span>@endif
          <button type="button" class="mt-steprow-go" data-mt-session="{{ $r['session'] }}">Session</button>
        @else
          <span class="p">{{ $r['shop'] }}</span>
        @endif
      </div>
    @empty
      <div class="mt-empty">None in this window.</div>
    @endforelse
  </div>
@endforeach
