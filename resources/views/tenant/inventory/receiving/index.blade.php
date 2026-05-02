@extends('layouts.tenant.app')
@php
  $pageTitle = 'Receiving';
  $tabs = ['draft' => 'Drafts', 'committed' => 'Committed', 'voided' => 'Voided'];
@endphp

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
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

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
