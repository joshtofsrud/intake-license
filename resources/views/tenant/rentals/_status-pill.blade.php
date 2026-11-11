{{-- the one status vocabulary. Pass $rental; the pill
     derives: cancelled / returned / Xh Ym overdue / due today / out /
     balance due / reserved. Theme-aware (.rf-st in rentals-studio.css, so any page using it must load that file). Used by the bookings list and detail; future
     surfaces include this rather than inventing their own colors. --}}
@php
  $pillBalance = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);
  $pillLateMin = ($rental->status === 'out' && $rental->due_at && $rental->due_at->isPast())
      ? (int) $rental->due_at->diffInMinutes(now()) : 0;
  $pillDueToday = $rental->status === 'out' && $rental->due_at && $pillLateMin === 0
      && tlocal($rental->due_at, 'Y-m-d') === tnow()->format('Y-m-d');
  [$pillClass, $pillLabel] = match (true) {
      $rental->status === 'cancelled' => ['off', 'cancelled'],
      $rental->status === 'returned'  => ['done', 'returned'],
      $pillLateMin > 0                => ['late',
          ($pillLateMin >= 60 ? floor($pillLateMin / 60) . 'h ' . ($pillLateMin % 60) . 'm' : $pillLateMin . 'm') . ' overdue'],
      $pillDueToday                   => ['due', 'due today'],
      $rental->status === 'out'       => ['out', 'out'],
      $pillBalance > 0                => ['bal', 'balance due'],
      default                         => ['res', 'reserved'],
  };
@endphp
<span class="rf-st {{ $pillClass }}">{{ $pillLabel }}</span>
