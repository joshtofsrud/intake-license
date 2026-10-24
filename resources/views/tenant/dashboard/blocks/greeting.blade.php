{{-- Block: greeting and summary (Brief). --}}
@php
  $bq = \App\Support\Dashboard\BriefView::queue($attention ?? []);
  $lateN = $bq['lateN']; $todayN = $bq['todayN'];
  $dayLine = \App\Support\Dashboard\BriefView::dayLine($today ?? []);
@endphp
    <h1 class="mb-h1">{{ \App\Support\Dashboard\BriefView::hello($greeting) }}</h1>
    <p class="mb-sum">
      @if ($lateN || $todayN)
        @if ($lateN)<b class="late">{{ $lateN }} {{ \Illuminate\Support\Str::plural('thing', $lateN) }} {{ $lateN === 1 ? 'is' : 'are' }} late</b>@endif
        @if ($lateN && $todayN) and @endif
        @if ($todayN)<b>{{ $todayN }} {{ $lateN ? '' : \Illuminate\Support\Str::plural('thing', $todayN) . ' ' }}{{ $todayN === 1 ? 'is' : 'are' }} due today</b>@endif.
      @else
        <b>All caught up.</b>
      @endif
      {{ $dayLine }}
    </p>
