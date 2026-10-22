@extends('layouts.tenant.app')
@php $pageTitle = 'My Classes'; @endphp
{{-- MARKER-CLASSES-P10: an instructor's own classes and who is coming --}}

@push('styles')
<style>
.mc-wrap{max-width:900px}
.mc-day{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);font-weight:600;margin:24px 0 6px}
.mc-cls{border-bottom:0.5px solid var(--ia-border);padding:12px 0}
.mc-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 14px}
.mc-head b{font-size:15px}
.mc-head span{font-size:12.5px;color:var(--ia-text-muted)}
.mc-head a{margin-left:auto;font-size:12.5px;color:var(--ia-text)}
.mc-ppl{margin-top:8px;display:grid;gap:4px}
.mc-p{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:13px;align-items:center}
.mc-f{font-size:11px;padding:2px 7px;border-radius:5px}
.mc-f.red{background:rgba(239,68,68,.14);color:#F87171}
.mc-f.amber{background:rgba(245,158,11,.14);color:#FBBF24}
.mc-f.green{background:rgba(132,204,22,.14);color:#A3E635}
.mc-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:18px}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">My classes</h1>
    <p class="ia-page-subtitle">{{ $resource ? 'Teaching as ' . $resource->name . ' · the next 7 days' : 'Classes you teach and who is coming' }}</p>
  </div>
  <div class="ia-page-head-right"><a href="{{ route('tenant.classes.checkin') }}" class="ia-btn ia-btn--secondary ia-btn--sm">Check-in</a></div>
</div>

@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:12px">{{ session('success') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:12px">{{ $errors->first() }}</div>@endif

<div class="mc-wrap">
@if(! $resource)
  <form method="POST" action="{{ route('tenant.classes.mine.claim') }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13.5px">
    @csrf
    <label for="mc-res">Which instructor are you?</label>
    <select name="resource_id" id="mc-res" class="ia-input" style="width:auto">
      @foreach($unclaimed as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
    </select>
    <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
  </form>
  @if($unclaimed->isEmpty())<p style="font-size:13px;color:var(--ia-text-muted);margin-top:10px">Every instructor is already linked to a staff login. Ask an owner to add you under Resources.</p>@endif
@else
  @forelse($sessions->groupBy(fn ($s) => tlocal($s->starts_at, 'l, M j')) as $day => $list)
    <div class="mc-day">{{ $day }}</div>
    @foreach($list as $s)
      <div class="mc-cls">
        <div class="mc-head">
          <b>{{ tlocal($s->starts_at, 'g:i A') }} · {{ $s->template?->name }}</b>
          <span>{{ $s->active_registrations_count }}/{{ $s->capacity_snapshot }} booked{{ $s->waitlist_count ? ' · ' . $s->waitlist_count . ' waiting' : '' }}</span>
          @if(tlocal($s->starts_at, 'Y-m-d') === now(tenant()->timezone())->toDateString())
            <a href="{{ route('tenant.classes.checkin', ['session' => $s->id]) }}">Check in ›</a>
          @else
            <a href="{{ route('tenant.classes.sessions.show', ['id' => $s->id]) }}">Open ›</a>
          @endif
        </div>
        @if(! empty($rosters[$s->id]) && count($rosters[$s->id]))
          <div class="mc-ppl">
            @foreach($rosters[$s->id] as $x)
              <div class="mc-p">{{ $x['name'] }}@if($x['reg']->status === 'checked_in')<span style="color:var(--ia-text-muted)">· in</span>@endif
                @foreach($x['flags'] as [$label, $tone])@if(in_array($tone, ['red', 'amber']) && ! str_starts_with($label, 'Owes') && $label !== 'Membership payment due')<span class="mc-f {{ $tone }}">{{ $label }}</span>@endif @endforeach
              </div>
            @endforeach
          </div>
        @endif
      </div>
    @endforeach
  @empty
    <p style="font-size:13.5px;color:var(--ia-text-muted);margin-top:16px">No classes for {{ $resource->name }} in the next 7 days.</p>
  @endforelse
  <div class="mc-legend">Shown to instructors: first visits, waivers, last credits and customer notes. Payments stay with the front desk.
    <form method="POST" action="{{ route('tenant.classes.mine.release') }}" style="display:inline">@csrf
      <button type="submit" style="background:none;border:none;color:var(--ia-text);text-decoration:underline;font-size:12px;cursor:pointer;padding:0">Not {{ $resource->name }}?</button></form>
  </div>
@endif
</div>
@endsection
