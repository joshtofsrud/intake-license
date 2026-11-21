{{-- MARKER-SITE-HEALTH: the list and the picked row's details. Shared by the finished page and a first check still running. --}}
@php
  $groups = \App\Support\SiteHealthView::GROUPS;
  $tone = ['text' => 'var(--sd-t2)', 'media' => 'var(--sd-t4)', 'business' => 'var(--sd-t3)', 'links' => 'var(--sd-t1)'];
@endphp
  <section class="sd-queue" id="shFix">
    <div class="sd-sec-h">
      <div><h2>{{ $sh['checked'] ? 'To fix' : 'Found so far' }}</h2><span>Biggest effect first</span></div>
      <div class="sd-seg" role="tablist" id="shSeg">
        <button type="button" class="on" data-f="all">All {{ $sh['counts']['all'] }}</button>
        @foreach ($groups as $g => $name)
          @if ($sh['counts'][$g] > 0)<button type="button" data-f="{{ $g }}">{{ $name }} {{ $sh['counts'][$g] }}</button>@endif
        @endforeach
        @if ($sh['done']->count() > 0)<button type="button" data-f="done">Done and set aside {{ $sh['done']->count() }}</button>@endif
      </div>
    </div>

    <div class="sd-list sh-list {{ $sh['has_visits'] ? 'has-v' : '' }}" id="shList">
      @foreach ($sh['open']->concat($sh['done']) as $r)
        @php $isDone = $r['status'] !== 'open'; @endphp
        <div class="sd-row sh-row" style="--c:{{ $tone[$r['grp']] }}" data-id="{{ $r['id'] }}" data-group="{{ $isDone ? 'done' : $r['grp'] }}" tabindex="0" role="button" @if ($isDone) hidden @endif>
          <div class="sd-av">{{ mb_substr($r['kind'], 0, 1) }}</div>
          <div class="sd-who"><b>{{ $r['label'] }}</b><small>{{ $r['items'] ? 'items online' : ($r['path'] ?? 'site-wide') }}</small></div>
          <div class="sd-job">{{ $r['problem'] }}</div>
          <div class="sd-st {{ ! $isDone && $r['impact'] === 'high' ? 'late' : '' }}">{{ $isDone ? ($r['status'] === 'fixed' ? 'Fixed' : 'Set aside') : ($r['impact'] === 'high' ? 'High impact' : $r['kind']) }}</div>
          <div class="sd-age">@if ($r['visits'])<span class="sh-v">{{ number_format($r['visits']) }}<small>visits / mo</small></span>@endif</div>
        </div>
        <template data-for="{{ $r['id'] }}">@include('tenant.site-health._detail', ['r' => $r])</template>
      @endforeach

      <div class="sd-none" id="shNone" @if ($sh['open']->count() > 0) hidden @endif>
        @if (! $sh['checked'])
          Nothing found yet.
        @elseif (($sh['google']['available'] ?? false) && ! ($sh['google']['connected'] ?? false))
          Nothing to fix right now. <a href="#shGoogle">Connect Google</a> to see what people search to find you.
        @else
          Nothing to fix right now. The next check runs tonight.
        @endif
      </div>
    </div>

    <section class="sd-detail sh-detail" id="shDetail" aria-live="polite" hidden></section>
  </section>
