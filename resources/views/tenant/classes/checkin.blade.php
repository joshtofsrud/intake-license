@extends('layouts.tenant.app')
@php $pageTitle = 'Class Check-in'; @endphp
{{-- MARKER-CLASSES-P9: the front desk's view of today's classes --}}

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.ck-grid{display:grid;grid-template-columns:240px minmax(0,1fr) 250px;gap:28px;align-items:start}
.ck-h{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);font-weight:600;margin-bottom:10px}
.ck-day a{display:block;padding:11px 12px;border-left:2px solid transparent;text-decoration:none;color:var(--ia-text);border-radius:0 var(--ia-r-md) var(--ia-r-md) 0}
.ck-day a:hover{background:var(--ia-surface)}
.ck-day a.on{background:var(--ia-accent-soft);border-left-color:var(--ia-accent)}
.ck-day a.done{opacity:.55}
.ck-day .t{font-size:12px;color:var(--ia-text-muted)}
.ck-day .n{font-size:14px;font-weight:600;margin:2px 0}
.ck-day .m{font-size:12px;color:var(--ia-text-muted)}
.ck-when{font-size:12.5px;color:var(--ia-text-muted)}
.ck-title{font-size:22px;font-weight:600;margin:4px 0 2px}
.ck-stats{display:flex;gap:28px;margin:16px 0}
.ck-stats b{display:block;font-size:22px;font-weight:600}
.ck-stats span{font-size:12px;color:var(--ia-text-muted)}
.ck-tools{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px}
.ck-search{flex:1;min-width:200px;padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-family:inherit}
.ck-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:0.5px solid var(--ia-border)}
.ck-av{width:32px;height:32px;border-radius:50%;background:var(--ia-surface-2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0}
.ck-name{font-size:14px;font-weight:500}
.ck-pass{font-size:12.5px;color:var(--ia-text-muted);margin-top:1px}
.ck-flags{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.ck-flag{font-size:11px;padding:2px 7px;border-radius:5px}
.ck-flag.red{background:rgba(239,68,68,.14);color:#F87171}
.ck-flag.amber{background:rgba(245,158,11,.14);color:#FBBF24}
.ck-flag.green{background:rgba(132,204,22,.14);color:#A3E635}
.ck-act{margin-left:auto;flex-shrink:0}
.ck-in{font-size:12.5px;padding:6px 14px;border-radius:var(--ia-r-md);background:var(--ia-accent-soft);color:var(--ia-accent);font-weight:500}
.ck-word{padding:10px 0;border-bottom:0.5px solid var(--ia-border)}
.ck-word .n{font-size:13.5px;font-weight:500}
.ck-word .f{font-size:12px;margin-top:2px}
.ck-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:12px}
.ck-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:200;padding:16px}
.ck-modal.open{display:flex}
.ck-box{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;max-width:460px;width:100%}
.ck-box h3{margin:0 0 8px;font-size:17px}
.ck-box p{font-size:13.5px;color:var(--ia-text-muted);line-height:1.5;margin:0 0 16px}
.ck-box .row{display:flex;flex-wrap:wrap;gap:8px}
@media(max-width:1100px){ .ck-grid{grid-template-columns:200px minmax(0,1fr)} .ck-side{grid-column:1/-1} }
@media(max-width:720px){ .ck-grid{grid-template-columns:1fr} .ck-day{display:flex;overflow-x:auto;gap:4px} .ck-day a{min-width:150px;border-left:none;border-bottom:2px solid transparent;border-radius:var(--ia-r-md)} .ck-day a.on{border-bottom-color:var(--ia-accent)} }
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">{{ now(tenant()->timezone())->format('l, M j · g:i A') }}</p>
  </div>
  <div class="ia-page-head-right" style="display:flex;gap:8px"> {{-- MARKER-CLASSES-P10 --}}
    <a href="{{ route('tenant.classes.mine') }}" class="ia-btn ia-btn--secondary ia-btn--sm">My classes</a>
    <a href="{{ route('tenant.classes.kiosk.open') }}" target="_blank" rel="noopener" class="ia-btn ia-btn--secondary ia-btn--sm">Open self check-in</a>
  </div>
</div>

@php $fullClasses = ! auth('tenant')->user() || auth('tenant')->user()->canAccessSection('classes'); @endphp {{-- MARKER-CLASSES-P10 --}}
@if($fullClasses)
<div class="cl-subnav-wrap"><nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab">Schedule</a>
  <a href="{{ route('tenant.classes.checkin') }}" class="cl-subnav-tab is-active">Check-in</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab">Packs</a>
  <a href="{{ route('tenant.classes.reports') }}" class="cl-subnav-tab">Reports</a>
  <a href="{{ route('tenant.classes.settings') }}" class="cl-subnav-tab">Settings</a>
</nav></div>
@endif

@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:12px">{{ session('success') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:12px">{{ $errors->first() }}</div>@endif

@if($today->isEmpty())
  <div style="padding:40px 0;text-align:center;color:var(--ia-text-muted)">No classes today. <a href="{{ route('tenant.classes.sessions') }}" style="color:var(--ia-text)">See the schedule</a></div>
@else
<div class="ck-grid">
  <div>
    <div class="ck-h">Today</div>
    <div class="ck-day">
      @foreach($today as $s)
        @php
          $left = max(0, $s->capacity_snapshot - $s->active_registrations_count);
          $state = $s->ends_at->isPast() ? 'finished' : ($s->starts_at->isPast() ? 'under way' : 'in ' . $s->starts_at->diffForHumans(null, true));
        @endphp
        <a href="{{ route('tenant.classes.checkin', ['session' => $s->id]) }}" class="{{ $session && $session->id === $s->id ? 'on' : '' }} {{ $s->ends_at->isPast() ? 'done' : '' }}">
          <div class="t">{{ tlocal($s->starts_at, 'g:i A') }} · {{ $state }}</div>
          <div class="n">{{ $s->template?->name }}</div>
          <div class="m">{{ $s->instructor_snapshot ? $s->instructor_snapshot . ' · ' : '' }}{{ $s->checked_in_count }}/{{ $s->active_registrations_count }} in · {{ $left ? $left . ' spots left' : 'Full' }}{{ $s->waitlist_count ? ' · ' . $s->waitlist_count . ' waiting' : '' }}</div>
        </a>
      @endforeach
    </div>
  </div>

  <div>
    @php
      $in       = $rows->where('reg.status', 'checked_in')->count();
      $expected = $rows->where('reg.status', 'registered')->count();
      $spots    = max(0, $session->capacity_snapshot - $rows->count());
    @endphp
    <div class="ck-when">{{ tlocal($session->starts_at, 'g:i A') }}–{{ tlocal($session->ends_at, 'g:i A') }}{{ $session->instructor_snapshot ? ' · with ' . $session->instructor_snapshot : '' }}</div>
    <div class="ck-title">{{ $session->template?->name }}</div>
    <div class="ck-stats">
      <div><b>{{ $in }}</b><span>checked in</span></div>
      <div><b>{{ $expected }}</b><span>still expected</span></div>
      <div><b>{{ $spots }}</b><span>spots left</span></div>
    </div>
    <div class="ck-tools">
      <input type="search" class="ck-search" placeholder="Find someone on this class" oninput="ckFilter(this.value)">
      @if($fullClasses)<a href="{{ route('tenant.classes.sessions.show', ['id' => $session->id]) }}#roster" class="ia-btn ia-btn--primary ia-btn--sm">+ Walk-in</a>@endif
      @if($expected)
        <form method="POST" action="{{ route('tenant.classes.checkin.all', ['id' => $session->id]) }}">@csrf
          <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm">Check in everyone here</button></form>
      @endif
    </div>

    @forelse($rows as $x)
      <div class="ck-row" data-name="{{ mb_strtolower($x['name']) }}">
        <div class="ck-av">{{ $x['initials'] }}</div>
        <div style="min-width:0">
          <div class="ck-name">{{ $x['name'] }}</div>
          <div class="ck-pass">{{ $x['pass'] }}</div>
          @if($x['flags'])
            <div class="ck-flags">@foreach($x['flags'] as [$label, $tone])<span class="ck-flag {{ $tone }}">{{ $label }}</span>@endforeach</div>
          @endif
        </div>
        <div class="ck-act">
          @if($x['reg']->status === 'checked_in')
            <span class="ck-in">Checked in</span>
          @elseif($x['needs_waiver'])
            <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" onclick="ckWaiver('{{ $x['reg']->id }}', @js($x['name']), @js((bool) $x['reg']->customer?->email))">Check in</button>
          @else
            <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['id' => $x['reg']->id]) }}">@csrf
              <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm">Check in</button></form>
          @endif
        </div>
      </div>
    @empty
      <div style="padding:24px 0;color:var(--ia-text-muted);font-size:13.5px">Nobody is booked into this class yet.</div>
    @endforelse
  </div>

  <div class="ck-side">
    <div class="ck-h">Needs a word at the door</div>
    @php $flagged = $rows->filter(fn ($x) => $x['flags'] && $x['reg']->status === 'registered'); @endphp
    @forelse($flagged as $x)
      <div class="ck-word">
        <div class="n">{{ $x['name'] }}</div>
        <div class="f">@foreach($x['flags'] as $i => [$label, $tone])<span style="color:{{ $tone === 'red' ? '#F87171' : ($tone === 'amber' ? '#FBBF24' : '#A3E635') }}">{{ $label }}</span>{{ $i < count($x['flags']) - 1 ? ' · ' : '' }}@endforeach</div>
      </div>
    @empty
      <div style="font-size:13px;color:var(--ia-text-muted)">Nothing to flag for the people still to arrive.</div>
    @endforelse
    <div class="ck-legend">Flags come from passes, memberships, payments, waivers and customer notes. Nobody types them in. People already checked in drop off this list.</div>
    {{-- MARKER-CLASSES-P10 --}}
    <div class="ck-h" style="margin-top:26px">Self check-in</div>
    <div class="ck-legend" style="margin-top:0">Open it on a tablet at the door. It shows classes starting within the hour. Members tap their name and sign the waiver there if they need to. It never shows prices, notes or flags. Anyone who owes money or has a membership payment due is asked to see the desk.</div>
    <form method="POST" action="{{ route('tenant.classes.kiosk.new-link') }}" style="margin-top:8px">@csrf
      <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" style="padding-left:0">Make a new link (stops the old one)</button></form>
  </div>
</div>
@endif

<div class="ck-modal" id="ck-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ck-box">
    <h3 id="ck-modal-title">Needs to sign the waiver</h3>
    <p>They haven't signed this version of your waiver. Get a paper signature, or email them a link to sign, and check them in either way.</p>
    <div class="row">
      <form method="POST" id="ck-paper">@csrf<input type="hidden" name="how" value="paper">
        <button type="submit" class="ia-btn ia-btn--secondary">Signed on paper</button></form>
      <form method="POST" id="ck-email">@csrf<input type="hidden" name="how" value="email">
        <button type="submit" class="ia-btn ia-btn--primary" id="ck-email-btn">Email a link to sign</button></form>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="document.getElementById('ck-modal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
function ckFilter(q) {
  q = q.trim().toLowerCase();
  document.querySelectorAll('.ck-row').forEach(function (r) { r.style.display = !q || r.dataset.name.indexOf(q) !== -1 ? '' : 'none'; });
}
function ckWaiver(id, name, hasEmail) {
  var base = @json(route('tenant.classes.checkin.waiver', ['id' => '__ID__'])).replace('__ID__', encodeURIComponent(id));
  document.getElementById('ck-paper').action = base;
  document.getElementById('ck-email').action = base;
  document.getElementById('ck-email').style.display = hasEmail ? '' : 'none';
  document.getElementById('ck-modal-title').textContent = name + ' needs to sign the waiver';
  document.getElementById('ck-modal').classList.add('open');
}
</script>
@endpush
