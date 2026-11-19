{{-- MARKER-BRAND-STOCK: a brand sold direct, supplied by Intake. The shop manages nothing here; this says what it gets. --}}
@php $sf = $b['stock'] ?? null; @endphp
<div style="border-top:.5px solid var(--ia-border);margin-top:14px;padding-top:14px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:8px">Supplied by Intake</div>
  <div style="font-size:12.5px;line-height:1.55;color:var(--ia-text-dim)">
    {{ $b['label'] }} sells to you direct. Intake keeps the {{ $b['label'] }} catalog and loads {{ $b['label'] }}'s dealer cost and stock for you,
    so there is nothing to connect or upload. Import from it like any other distributor.
    @if ($sf && $sf->loaded_at)
      Cost and stock were last updated {{ \Carbon\Carbon::parse($sf->loaded_at)->diffForHumans() }}.
    @else
      Cost and stock haven't been loaded yet: imported items come in at list price with no cost until they are.
    @endif
    This never changes your own on-hand counts or prices you set yourself.
  </div>
</div>
