@extends('layouts.tenant.app')
@php
  $pageTitle = 'Receiving';
  $tabs = ['draft' => 'Drafts', 'committed' => 'Committed', 'voided' => 'Voided'];
@endphp


@push('styles')
<style>
/* Receiving mobile list (patch #38) — scoped via .recv- prefix.
   Desktop table stays. Mobile cards + pill tabs. */
.recv-mobile{display:none}
.recv-mobile-list{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.recv-card-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.recv-card-m:last-child{border-bottom:none}
.recv-card-m:active{background:var(--ia-hover)}
.recv-top-m{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.recv-num-m{font-size:14.5px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums}
.recv-dist-m{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.recv-status-m{display:inline-flex;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:500;flex-shrink:0;text-transform:capitalize}
.recv-status-m.draft{background:rgba(250,180,106,.15);color:#FAB46A}
.recv-status-m.committed{background:var(--ia-accent-soft);color:var(--ia-accent)}
.recv-status-m.voided{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.recv-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.recv-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.recv-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.recv-meta-value-m{color:var(--ia-text);font-weight:500}
.recv-unexpected-m{color:#FAB46A}

/* Pill tabs */
.recv-tabs-m{display:none;gap:6px;margin-bottom:14px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.recv-tabs-m::-webkit-scrollbar{display:none}
.recv-tab-m{flex-shrink:0;padding:7px 14px;border-radius:999px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);font-size:13px;display:inline-flex;align-items:center;gap:5px;text-decoration:none;font-family:inherit}
.recv-tab-m.active{background:var(--ia-accent);color:#000;border-color:var(--ia-accent)}
.recv-tab-count-m{font-size:11px;opacity:.6;font-variant-numeric:tabular-nums}

/* Mobile head action row */
.recv-head-m{display:none}
.recv-icon-btn-m{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);text-decoration:none;font-family:inherit;font-size:16px;cursor:pointer;border-width:0;padding:0}
.recv-icon-btn-m.primary{background:var(--ia-accent);color:#000;border-color:var(--ia-accent);font-weight:600}

@media(max-width:640px){
  .ia-tabs,
  .ia-toolbar,
  .ia-table-wrap{display:none !important}
  .ia-page-head .ia-page-actions{display:none}
  .recv-head-m{display:flex;gap:6px;align-items:center;margin-left:auto}
  .recv-tabs-m{display:flex}
  .recv-mobile{display:block}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Receiving</h1>
    <p class="ia-page-subtitle">Shipments and stock receipts</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">← Inventory</a>
    <form method="POST" action="{{ route('tenant.inventory.receiving.create') }}" style="display:inline">
      @csrf
      <button type="submit" class="ia-btn ia-btn--primary">+ New shipment</button>
    </form>
  </div>
  {{-- Mobile-only icon button: New shipment (POST via form below). --}}
  <div class="recv-head-m">
    <form method="POST" action="{{ route('tenant.inventory.receiving.create') }}" style="display:inline">
      @csrf
      <button type="submit" class="recv-icon-btn-m primary" title="New shipment" aria-label="New shipment">+</button>
    </form>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- Mobile pill tabs (≤640px). Same hrefs as desktop tabs. --}}
<div class="recv-tabs-m">
  @foreach($tabs as $key => $label)
    @php $count = $counts[$key] ?? 0; @endphp
    <a href="{{ route('tenant.inventory.receiving.index', ['tab' => $key]) }}" class="recv-tab-m {{ $tab === $key ? 'active' : '' }}">
      {{ $label }} <span class="recv-tab-count-m">{{ $count }}</span>
    </a>
  @endforeach
</div>

<div class="ia-tabs" style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--ia-border)">
  @foreach($tabs as $key => $label)
    @php $count = $counts[$key] ?? 0; @endphp
    <a href="{{ route('tenant.inventory.receiving.index', ['tab' => $key]) }}"
       class="ia-tab {{ $tab === $key ? 'ia-tab--active' : '' }}"
       style="padding:9px 14px;font-size:13px;text-decoration:none;color:{{ $tab === $key ? 'var(--ia-text)' : 'var(--ia-text-muted)' }};border-bottom:2px solid {{ $tab === $key ? 'var(--ia-accent)' : 'transparent' }};margin-bottom:-1px">
      {{ $label }} <span style="color:var(--ia-text-muted);font-size:11px">{{ $count }}</span>
    </a>
  @endforeach
</div>

<form method="get" action="{{ route('tenant.inventory.receiving.index') }}" class="ia-toolbar">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
         placeholder="Search shipment number or distributor…" style="max-width:340px">
  @if($locations->count() > 1)
    <select name="location" class="ia-input" style="width:auto">
      <option value="">All locations</option>
      @foreach($locations as $loc)
        <option value="{{ $loc->id }}" @selected($location === $loc->id)>{{ $loc->name }}</option>
      @endforeach
    </select>
  @endif
  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $location)
    <a href="{{ route('tenant.inventory.receiving.index', ['tab' => $tab]) }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

<div class="ia-card">
  @if($shipments->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      @if($tab === 'draft')
        No draft shipments. Click "+ New shipment" above to start one.
      @else
        No {{ $tab }} shipments yet.
      @endif
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Shipment</th>
          <th>Distributor</th>
          @if($locations->count() > 1)<th>Location</th>@endif
          <th>Date</th>
          <th style="text-align:right">Lines</th>
          <th style="text-align:right">Units</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($shipments as $s)
          <tr>
            <td>
              <strong>{{ $s->shipment_number }}</strong>
              @if($s->notes)<div style="font-size:11px;color:var(--ia-text-muted);margin-top:2px">{{ Str::limit($s->notes, 60) }}</div>@endif
            </td>
            <td>{{ $s->distributor_name ?? '—' }}</td>
            @if($locations->count() > 1)<td>{{ $s->location?->name ?? '—' }}</td>@endif
            <td>{{ $s->received_date?->format('M j, Y') ?? '—' }}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums">
              {{ $s->expected_count + $s->received_count + $s->unexpected_count }}
            </td>
            <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $s->received_count }}</td>
            <td style="text-align:right">
              @if($s->status === 'draft')
                <a href="{{ route('tenant.inventory.receiving.edit', ['id' => $s->id]) }}" class="ia-btn ia-btn--ghost">Edit →</a>
              @else
                <a href="{{ route('tenant.inventory.receiving.show', ['id' => $s->id]) }}" class="ia-btn ia-btn--ghost">View →</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>

{{-- Mobile card list (≤640px) --}}
<div class="recv-mobile">
  @if($shipments->isEmpty())
    <div class="recv-mobile-list" style="padding:40px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px">
      @if($tab === 'draft')
        No draft shipments. Tap + above to start one.
      @else
        No {{ $tab }} shipments yet.
      @endif
    </div>
  @else
    <div class="recv-mobile-list">
      @foreach($shipments as $s)
        @php
          $linkRoute = $s->status === 'draft'
            ? route('tenant.inventory.receiving.edit', ['id' => $s->id])
            : route('tenant.inventory.receiving.show', ['id' => $s->id]);
          $totalLines = $s->expected_count + $s->received_count + $s->unexpected_count;
        @endphp
        <a href="{{ $linkRoute }}" class="recv-card-m">
          <div class="recv-top-m">
            <div style="min-width:0">
              <div class="recv-num-m">{{ $s->shipment_number }}</div>
              <div class="recv-dist-m">
                {{ $s->distributor_name ?? 'No distributor' }}@if($s->received_date) · {{ $s->received_date->format('D, M j') }}@endif
              </div>
            </div>
            <span class="recv-status-m {{ $s->status }}">{{ $s->status }}</span>
          </div>
          <div class="recv-meta-row-m">
            <span class="recv-meta-item-m"><span class="recv-meta-label-m">Lines</span> <span class="recv-meta-value-m">{{ $totalLines }}</span></span>
            <span class="recv-meta-item-m"><span class="recv-meta-label-m">Units</span> <span class="recv-meta-value-m">{{ $s->received_count }}</span></span>
            @if($s->unexpected_count > 0)
              <span class="recv-meta-item-m recv-unexpected-m" style="margin-left:auto">{{ $s->unexpected_count }} unexpected</span>
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

@if($total > $perPage)
  <div class="ia-pagination">
    @php
      $pages = (int) ceil($total / $perPage);
      $qs = function($p) use ($tab, $search, $location) {
        return http_build_query(array_filter([
          'tab' => $tab, 's' => $search, 'location' => $location, 'page' => $p,
        ]));
      };
    @endphp
    @if($page > 1)<a href="?{{ $qs($page - 1) }}" class="ia-btn ia-btn--ghost">← Prev</a>@endif
    <span class="ia-pagination-info">Page {{ $page }} of {{ $pages }}</span>
    @if($page < $pages)<a href="?{{ $qs($page + 1) }}" class="ia-btn ia-btn--ghost">Next →</a>@endif
  </div>
@endif

@endsection
