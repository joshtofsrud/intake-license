{{-- Block: greeting and summary (Brief). The counts are of what this person can
     see; the bookings line only for people who can open Schedule. --}}
@php $see ??= \App\Support\Dashboard\BlockAccess::for(auth('tenant')->user()); @endphp
@php
  $bq = \App\Support\Dashboard\BriefView::queue($attention ?? []);
  $lateN = $bq['lateN']; $todayN = $bq['todayN'];
  $dayLine = $see->can('schedule') ? \App\Support\Dashboard\BriefView::dayLine($today ?? []) : '';
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
