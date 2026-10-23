@extends('layouts.tenant.app')
@php $pageTitle = 'Recurring schedules'; @endphp

{{-- MARKER-CLASSES-UI1: the Recurring schedules tab (moved off the Schedule page). --}}
@push('styles')
<style>
.rs-table{width:100%;border-collapse:collapse;font-size:13px}
.rs-table th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:8px 10px;border-bottom:0.5px solid var(--ia-border)}
.rs-table td{padding:12px 10px;border-bottom:0.5px solid var(--ia-border);vertical-align:middle}
.rs-table tr.is-ended td{opacity:.5}
.rs-name{font-weight:600;color:var(--ia-text)}
.rs-sub{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.rs-num{font-variant-numeric:tabular-nums}
.rs-stop{display:flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.rs-stop input{width:auto;padding:5px 8px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:12.5px;font-family:inherit}
.rs-note{font-size:12px;color:var(--ia-text-muted);margin-top:14px;line-height:1.55;max-width:760px}
.rs-empty{padding:48px;text-align:center;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);background:var(--ia-surface)}
@media(max-width:760px){
  .rs-table thead{display:none}
  .rs-table,.rs-table tbody,.rs-table tr,.rs-table td{display:block;width:100%}
  .rs-table tr{border-bottom:0.5px solid var(--ia-border);padding:10px 0}
  .rs-table td{border:none;padding:3px 0}
  .rs-stop{justify-content:flex-start}
  .rs-table td[data-l]::before{content:attr(data-l) ' · ';color:var(--ia-text-muted);font-size:12px}
}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">Classes that repeat every week. Each one fills the schedule {{ $publishWeeks }} week{{ $publishWeeks === 1 ? '' : 's' }} ahead.</p>
  </div>
  <div class="ia-page-head-right">
    <a href="{{ route('tenant.classes.sessions', ['add' => 'weekly']) }}" class="ia-btn ia-btn--primary">+ Repeating class</a>
  </div>
</div>

<x-tenant.schedule-tabs active="classes" />
@include('tenant.classes._tabs', ['active' => 'schedules'])

@if($schedules->isEmpty())
  <div class="rs-empty">
    <div style="font-size:15px;font-weight:500;margin-bottom:6px">No repeating classes yet</div>
    <div style="font-size:13px;color:var(--ia-text-muted)">Set one up once, like Mon, Wed, Fri at 6:00am, and the classes are added for you. <a href="{{ route('tenant.classes.sessions', ['add' => 'weekly']) }}" style="color:var(--ia-accent)">Add a repeating class</a></div>
  </div>
@else
  <table class="rs-table">
    <thead>
      <tr><th>Class</th><th>When</th><th>Instructor</th><th>Runs</th><th>Next class</th><th></th></tr>
    </thead>
    <tbody>
      @foreach($schedules as $s)
        @php
          $ended = $s->ends_on && $s->ends_on->lt(tnow()->startOfDay());
          $up    = $next->get($s->id, collect());
          $n     = $up->first();
          $iid   = $s->instructor_resource_id ?? $s->template?->instructor_resource_id;
        @endphp
        <tr class="{{ $ended ? 'is-ended' : '' }}">
          <td><div class="rs-name">{{ $s->template?->name ?? 'Class' }}</div>
            <div class="rs-sub">{{ $s->template?->duration_minutes }} min · {{ $s->capacity ?? $s->template?->default_capacity }} spots</div></td>
          <td data-l="When">{{ $s->daysLabel() }} · {{ strtolower(\Carbon\Carbon::createFromFormat('H:i', $s->start_time)->format('g:iA')) }}</td>
          <td data-l="Instructor">{{ $iid ? ($instructorNames[$iid] ?? '—') : '—' }}</td>
          <td data-l="Runs">{{ $s->starts_on?->format('M j') }} – {{ $s->ends_on ? $s->ends_on->format('M j, Y') : 'no end date' }}</td>
          <td data-l="Next" class="rs-num">
            @if($n)
              <a href="{{ route('tenant.classes.sessions.show', ['id' => $n->id]) }}" style="color:inherit">{{ tlocal($n->starts_at, 'D M j') }}</a>
              <span style="color:var(--ia-text-muted)">· {{ $n->active_registrations_count }}/{{ $n->capacity_snapshot }}</span>
              <div class="rs-sub">{{ $up->count() }} coming up</div>
            @else
              <span style="color:var(--ia-text-muted)">{{ $ended ? 'Ended' : 'None yet' }}</span>
            @endif
          </td>
          <td>
            @unless($ended)
            <form method="POST" action="{{ route('tenant.classes.schedules.stop', ['id' => $s->id]) }}" class="rs-stop">
              @csrf
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="this.hidden=true;this.nextElementSibling.hidden=false">Stop…</button>
              <span hidden>
                <label style="font-size:12px;color:var(--ia-text-muted)">Last class on</label>
                <input type="date" name="last_day" required value="{{ tnow()->toDateString() }}" min="{{ $s->starts_on?->format('Y-m-d') }}">
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#EF4444">Stop it</button>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="this.parentElement.hidden=true;this.parentElement.previousElementSibling.hidden=false">Keep</button>
              </span>
            </form>
            @endunless
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p class="rs-note">Stopping removes later classes nobody has booked; classes with bookings stay until you cancel them. To change a time, instructor or capacity, open any of its classes and choose "This and later classes". How far ahead classes are added is in Rules &amp; settings.</p>
@endif
@endsection
