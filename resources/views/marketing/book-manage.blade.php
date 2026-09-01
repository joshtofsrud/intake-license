@extends('marketing.layout')
{{-- MARKER-SCHED-PUBLIC --}}
@section('title', 'Your call — Intake')
@php
    $s = $booking->startsForBooker();
    $e = $booking->ends_at->copy()->setTimezone($s->getTimezone());
    $typeName = $booking->type?->name ?? 'Call';
    $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($typeName . ' — Intake')
        . '&dates=' . $booking->starts_at->copy()->utc()->format('Ymd\THis\Z') . '/' . $booking->ends_at->copy()->utc()->format('Ymd\THis\Z')
        . '&details=' . rawurlencode('Reschedule or cancel: ' . $booking->publicUrl())
        . ($booking->location_detail ? '&location=' . rawurlencode($booking->location_detail) : '');
    $where = match ($booking->location_mode) {
        'phone'     => "Phone — we'll call " . ($booking->location_detail ?: 'you'),
        'in_person' => 'In person' . ($booking->location_detail ? ' — ' . $booking->location_detail : ''),
        default     => $booking->location_detail ? 'Google Meet' : 'Video call — link to follow',
    };
@endphp
@section('content')
<section class="mk-section" style="border-bottom:none">
  <div class="mk-container" style="max-width:640px">
    @if($booking->status === 'cancelled')
      <div class="mk-eyebrow">Cancelled</div>
      <h1 class="mk-section-title">This call is cancelled.</h1>
      <p class="mk-section-sub">It was on {{ $s->format('l, F j') }} at {{ $s->format('g:i a') }}. If you'd still like to talk, pick a fresh time.</p>
      @if($booking->type && $booking->type->isBookable())<a href="{{ $booking->type->publicUrl() }}" class="mk-btn mk-btn--primary">Pick a new time</a>@endif
    @else
      @if($isNew)
        <div style="width:44px;height:44px;border-radius:50%;background:var(--mk-accent);color:var(--mk-accent-text);display:grid;place-items:center;font-size:22px;margin-bottom:14px">✓</div>
        <h1 class="mk-section-title">You're booked, {{ explode(' ', trim($booking->name))[0] }}.</h1>
        <p class="mk-section-sub">A confirmation is on its way to {{ $booking->email }}.</p>
      @elseif(request()->query('moved'))
        <h1 class="mk-section-title">Moved.</h1>
        <p class="mk-section-sub">Your call is now at the time below. A fresh confirmation is on its way to {{ $booking->email }}.</p>
      @else
        <div class="mk-eyebrow">Your call</div>
        <h1 class="mk-section-title">{{ $typeName }}</h1>
        @if($booking->status === 'completed')<p class="mk-section-sub">This one's done — thanks for the time.</p>@endif
      @endif

      <div style="background:var(--mk-bg2);border:.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:18px 20px;margin:8px 0 18px;display:grid;grid-template-columns:90px 1fr;gap:8px 12px;font-size:15px">
        <span style="color:var(--mk-dim)">What</span><span>{{ $typeName }}</span>
        <span style="color:var(--mk-dim)">When</span><span>{{ $s->format('l, F j') }} · {{ $s->format('g:i') }}–{{ $e->format('g:i a') }} <span style="color:var(--mk-muted)">({{ $s->getTimezone()->getName() }})</span></span>
        <span style="color:var(--mk-dim)">Where</span><span>{{ $where }}@if($booking->location_mode === 'meet' && $booking->location_detail) — <a href="{{ $booking->location_detail }}" style="color:inherit">{{ preg_replace('#^https?://#', '', $booking->location_detail) }}</a>@endif</span>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="{{ $gcal }}" target="_blank" rel="noopener" class="mk-btn mk-btn--ghost mk-btn--sm">Add to Google Calendar</a>
        <a href="{{ route('book.ics', $booking->token) }}" class="mk-btn mk-btn--ghost mk-btn--sm">Apple / Outlook (.ics)</a>
        @if($canMove)
          <a href="{{ route('book.reschedule.form', $booking->token) }}" class="mk-btn mk-btn--ghost mk-btn--sm">Reschedule</a>
          <button type="button" id="bc-cancel-btn" class="mk-btn mk-btn--ghost mk-btn--sm" onclick="document.getElementById('bc-cancel').style.display='block';this.style.display='none'">Cancel</button>
        @endif
      </div>

      @if($canMove)
      <div id="bc-cancel" style="display:none;margin-top:16px;background:var(--mk-bg2);border:.5px solid var(--mk-border);border-radius:var(--mk-r);padding:14px 16px">
        <div style="font-weight:600;margin-bottom:4px">Cancel this call?</div>
        <div style="font-size:14px;color:var(--mk-muted);margin-bottom:12px">The time opens up for someone else. You can always book again.</div>
        <form method="POST" action="{{ route('book.cancel', $booking->token) }}" style="display:flex;gap:8px">
          @csrf
          <button type="submit" class="mk-btn mk-btn--primary mk-btn--sm">Yes, cancel it</button>
          <button type="button" class="mk-btn mk-btn--ghost mk-btn--sm" onclick="document.getElementById('bc-cancel').style.display='none';document.getElementById('bc-cancel-btn').style.display=''">Keep it</button>
        </form>
      </div>
      @endif
    @endif
  </div>
</section>
@endsection
