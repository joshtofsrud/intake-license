@extends('layouts.tenant.app')
{{-- MARKER-SITE-HEALTH: what to fix first so more people find the shop. Drawn in the
     Studio design: coloured tiles, the list with the picked row's details under it,
     and Google's numbers beside them. Help sits behind the (i) marks. --}}
@section('title', 'Site health')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-studio.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-studio.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/site-health.css') }}?v={{ filemtime(public_path('css/tenant/site-health.css')) }}">
@endpush

@section('content')
@php
  $run = $sh['run'];
  $shUrls = [
      'page' => route('tenant.site_health.index'), 'status' => route('tenant.site_health.status'),
      'check' => route('tenant.site_health.check'), 'stop' => route('tenant.site_health.stop'), 'resume' => route('tenant.site_health.resume'),
      'refresh' => route('tenant.site_health.google.refresh'), 'pick' => route('tenant.site_health.google.pick'), 'reply' => route('tenant.site_health.google.reply'),
  ];
@endphp
<div class="sd sh" id="sh" data-urls='@json($shUrls)' data-running="{{ $run['running'] ? 1 : 0 }}" data-google-running="{{ ($sh['google']['running'] ?? false) ? 1 : 0 }}">
  <header class="sd-head" id="shHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Site health</h1>
      <p class="sd-sub">What to fix first so more people find you.</p>
    </div>
    <div class="sd-head-r">
      <span class="sh-run" id="shRun" @if (! $run['running']) hidden @endif>
        <span class="sh-dot"></span>Checking <b id="shN">{{ number_format($run['done']) }}</b> of <span id="shT">{{ number_format($run['total']) }}</span> · <span id="shWhat">{{ $run['what'] }}</span>
        <button type="button" class="ia-btn ia-btn--sm" id="shStop">Stop</button>
      </span>
      <button type="button" class="ia-btn ia-btn--sm" id="shResume" @if ($run['running'] || ! $run['stopped']) hidden @endif>Resume from {{ number_format($run['done']) }} of {{ number_format($run['total']) }}</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" id="shCheck" @if ($run['running']) hidden @endif>{{ $run['stopped'] ? 'Start over' : 'Check now' }}</button>
    </div>
    <div class="sh-bar" id="shBar" @if (! $run['running']) hidden @endif><i style="width:{{ $run['pct'] }}%"></i></div>
    @if ($run['error'] && ! $run['running'])<p class="sh-err" id="shErr">{{ $run['error'] }}</p>@endif
  </header>

  @if (request('google') === 'connected')<p class="sh-flash">Google is connected. Your numbers are being read now.</p>@endif
  @if (request('google_error'))<p class="sh-flash error">{{ \Illuminate\Support\Str::limit((string) request('google_error'), 220) }}</p>@endif
  @if (session('success'))<p class="sh-flash">{{ session('success') }}</p>@endif
  @if (session('error'))<p class="sh-flash error">{{ session('error') }}</p>@endif

  <div class="sh-grid {{ ! ($sh['google']['available'] ?? false) && ! $sh['basics'] ? 'solo' : '' }}" id="shGrid">
    <div id="shMain">@include('tenant.site-health._main')</div>
    <aside class="sd-rail" id="shRail">@include('tenant.site-health._rail')</aside>
  </div>

  <details class="sh-legend">
    <summary>What is and isn't checked</summary>
    <p>Checked: every public page and every item shown online, the way a search engine reads them. Not checked: staff pages, carts, checkout, customer accounts, links that leave your site, and photos placed on pages. Nothing on your site changes until you save a fix, and a saved fix can be undone until the next check. Google is only read once you connect it.</p>
  </details>
</div>

{{-- Connect Google: says what is asked for before leaving for Google --}}
@if ($sh['google']['available'] ?? false)
<div class="sh-scrim" id="shScrim" hidden>
  <div class="sh-dlg" role="dialog" aria-modal="true" aria-labelledby="shDlgT">
    <h4 id="shDlgT">Connect Google?</h4>
    <p>One sign-in for {!! '<b>' . collect($sh['google']['sources'])->pluck('name')->map(fn ($n) => e($n))->join('</b>, <b>', '</b> and <b>') . '</b>' !!}. Free. Intake only reads{{ isset($sh['google']['sources']['gbp']) ? ', and posts a review reply when you press Post' : '' }}.</p>
    <div class="sh-dlg-f">
      <button type="button" class="ia-btn ia-btn--sm" data-sh-close>Not now</button>
      <form method="POST" action="{{ route('tenant.site_health.google.connect') }}">@csrf<button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Continue to Google</button></form>
    </div>
  </div>
</div>
@endif
@endsection

@push('scripts')
  <script src="{{ asset('js/tenant/site-health.js') }}?v={{ filemtime(public_path('js/tenant/site-health.js')) }}" defer></script>
@endpush
