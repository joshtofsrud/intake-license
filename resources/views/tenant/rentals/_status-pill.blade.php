{{-- MARKER-PATCH-234 — the one status vocabulary. Pass $rental; the pill
     derives: cancelled / returned / Xh Ym overdue / due today / out /
     balance due / reserved. Used by the bookings list and detail; future
     surfaces include this rather than inventing their own colors. --}}
@php
  $pillBalance = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);
  $pillLateMin = ($rental->status === 'out' && $rental->due_at && $rental->due_at->isPast())
      ? (int) $rental->due_at->diffInMinutes(now()) : 0;
  $pillDueToday = $rental->status === 'out' && $rental->due_at && $pillLateMin === 0
      && tlocal($rental->due_at, 'Y-m-d') === tnow()->format('Y-m-d');
  [$pillBg, $pillColor, $pillLabel] = match (true) {
      $rental->status === 'cancelled' => ['rgba(255,255,255,.06)', 'rgba(255,255,255,.45)', 'cancelled'],
      $rental->status === 'returned'  => ['rgba(52,211,153,.12)', '#34d399', 'returned'],
      $pillLateMin > 0                => ['rgba(239,68,68,.14)', '#ef4444',
          ($pillLateMin >= 60 ? floor($pillLateMin / 60) . 'h ' . ($pillLateMin % 60) . 'm' : $pillLateMin . 'm') . ' overdue'],
      $pillDueToday                   => ['rgba(91,163,208,.2)', '#8ec5ea', 'due today'],
      $rental->status === 'out'       => ['rgba(91,163,208,.13)', '#5BA3D0', 'out'],
      $pillBalance > 0                => ['rgba(224,168,46,.13)', '#E0A82E', 'balance due'],
      default                         => ['rgba(224,168,46,.13)', '#E0A82E', 'reserved'],
  };
@endphp
<span style="font-size:10.5px;font-weight:600;border-radius:999px;padding:2.5px 10px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;letter-spacing:.02em;background:{{ $pillBg }};color:{{ $pillColor }}"><span style="width:5px;height:5px;border-radius:50%;background:currentColor"></span>{{ $pillLabel }}</span>
