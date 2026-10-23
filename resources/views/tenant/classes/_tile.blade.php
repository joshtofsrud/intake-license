{{-- MARKER-CLASSES-UI1: one class in the week grid or the day list. --}}
@php
  $cap    = max(0, (int) $s->capacity_snapshot);
  $booked = (int) $s->active_registrations_count;
  $pct    = $cap > 0 ? min(100, round($booked / $cap * 100)) : 0;
  $tone   = $cap > 0 && $booked >= $cap ? 'full' : ($pct >= 75 ? 'busy' : 'ok');
  $isCancelled = $s->status === 'cancelled';
  $isDone = ! $isCancelled && $s->ends_at && $s->ends_at->isPast();
  $isSoon = ! $isCancelled && ! $isDone && $s->starts_at->lte(now()->addHours(2));
  $who    = $s->instructorResource?->name ?? $s->instructor_snapshot;
@endphp
<a href="{{ route('tenant.classes.sessions.show', ['id' => $s->id]) }}"
   class="cg-tile {{ $isDone ? 'is-done' : '' }} {{ $isSoon ? 'is-soon' : '' }} {{ $isCancelled ? 'is-cancelled' : '' }}"
   title="{{ $s->template?->name }} · {{ tlocal($s->starts_at, 'D g:ia') }}">
  <div class="cg-top">
    <span class="cg-time">{{ strtolower(tlocal($s->starts_at, 'g:iA')) }}</span>
    @if($isCancelled)
      <span class="cg-count">Cancelled</span>
    @else
      <span class="cg-count {{ $tone }}">{{ $booked }}/{{ $cap }}</span>
    @endif
  </div>
  <div class="cg-name">{{ $s->template?->name ?? 'Class' }}</div>
  <div class="cg-who">{{ $who ?: 'No instructor' }} · {{ $s->template?->duration_minutes }} min</div>
  @unless($isCancelled)
    <div class="cg-fill"><i class="{{ $tone }}" style="width:{{ $pct }}%"></i></div>
  @endunless
  @if(! $isCancelled && $s->waitlist_count > 0)
    <div class="cg-wait">+{{ $s->waitlist_count }} waitlist</div>
  @endif
</a>
