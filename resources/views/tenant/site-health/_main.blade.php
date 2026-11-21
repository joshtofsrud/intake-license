{{-- MARKER-SITE-HEALTH: the tiles, the list and the picked row's details. Swapped in whole after a check, a fix or an undo. --}}
@php
  $groups = \App\Support\SiteHealthView::GROUPS;
  $tone = ['text' => 'var(--sd-t2)', 'media' => 'var(--sd-t4)', 'business' => 'var(--sd-t3)', 'links' => 'var(--sd-t1)'];
  $studio = ['tiles' => $sh['tiles']];
@endphp

@php $run = $sh['run']; $live = $sh['live']; @endphp
@if (! $sh['checked'] && ! $run['running'] && ! $run['stopped'])
  {{-- before the first check: a dimmed picture of the page to come, and one button --}}
  <section class="sh-ghost">
    <div class="sh-ghost-art" aria-hidden="true">
      <div class="sd-tiles sd-n3">
        <div class="sd-tile sd-t3"><div class="sd-tile-h"><span>Site health</span></div><div class="sd-big"><b>–</b></div><div class="sd-bars">@foreach ([40, 46, 52, 50, 64, 78] as $bh)<span style="--h:{{ $bh }}%"></span>@endforeach</div></div>
        <div class="sd-tile sd-t2"><div class="sd-tile-h"><span>To fix</span></div><div class="sd-big"><b>–</b></div><div class="sd-stack"><span class="p1" style="--w:44%"></span><span class="p2" style="--w:22%"></span><span class="p3" style="--w:22%"></span><span class="p4 bad" style="--w:12%"></span></div></div>
        <div class="sd-tile sd-t1"><div class="sd-tile-h"><span>Pages and items checked</span></div><div class="sd-big"><b>–</b></div><div class="sd-meter"><span style="--w:100%"></span></div></div>
      </div>
      @foreach ([62, 48, 70, 40, 56] as $w)
        <div class="sh-ghost-row"><i></i><span style="width:{{ $w * 0.4 }}%"></span><span style="width:{{ $w }}%"></span><em></em></div>
      @endforeach
    </div>
    <div class="sh-ghost-cta">
      <h2>See what to fix first</h2>
      <p>Reads your public pages and items. Changes nothing.</p>
      <button type="button" class="ia-btn ia-btn--primary" data-sh-check>Check now</button>
    </div>
  </section>

@elseif (! $sh['checked'])
  {{-- the first check, as it runs: how far it has read and what it has found so far --}}
  <section class="sh-live" id="shLive">
    <div class="sh-live-n">
      <b id="shLiveN">{{ number_format($run['done']) }}</b>
      <span>of <span id="shLiveT">{{ number_format($run['total']) }}</span> pages and items {{ $run['running'] ? 'read' : 'read before it stopped' }}</span>
    </div>
    <div class="sh-tally">
      @foreach ($groups as $g => $name)
        <span style="--c:{{ $tone[$g] }}"><b data-sh-tally="{{ $g }}">{{ number_format($live['found'][$g]) }}</b>{{ $name }}</span>
      @endforeach
    </div>
    <p class="sh-cap" id="shLiveItems" @if (! $live['photo'] && ! $live['words']) hidden @endif>Items so far: <b data-sh-tally="photo">{{ number_format($live['photo']) }}</b> with no photo · <b data-sh-tally="words">{{ number_format($live['words']) }}</b> with almost no description</p>
  </section>
  @include('tenant.site-health._list')

@else
  @if (! ($sh['google']['available'] ?? false))<div class="sh-basics-top">@include('tenant.site-health._basics')</div>@endif
  @include('tenant.dashboard.blocks.studio-tiles')
  @include('tenant.site-health._list')
@endif
