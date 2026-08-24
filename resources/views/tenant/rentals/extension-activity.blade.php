@extends('layouts.tenant.app')
{{-- MARKER-RENTAL-EXT-P2 — offers activity. --}}
@section('title', 'Last-minute offers')
@section('content')
{{-- MARKER-RENTAL-EXT-HEADORDER — nav first, then the title block, which
     is what Desk / Fleet / Availability / Settings all do. Reversed, this
     page looked like the only one in rentals with a page header. --}}
@include('layouts.tenant._rental-nav', ['active' => 'offers'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Last-minute offers</h1>
    <p class="ia-page-subtitle">Auto-extension activity · last 30 days.</p>
  </div>
  <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $filter, 'export' => 'csv']) }}" class="ia-btn">Export CSV</a>
</div>

<div class="ia-stat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:22px">
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Offers sent</div>
    <div class="ia-stat-value">{{ $sent }}</div>
    <div class="ia-stat-delta {{ $sent >= $sentPrev ? '' : 'down' }}">{{ $sent >= $sentPrev ? '+' : '' }}{{ $sent - $sentPrev }} vs. prev. 30d</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Accepted</div>
    <div class="ia-stat-value">{{ $accepted }}</div>
    <div class="ia-stat-delta">{{ $convPct }}% conversion</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Revenue captured</div>
    <div class="ia-stat-value">{{ format_money($revenue) }}</div>
    <div class="ia-stat-delta">{{ $accepted > 0 ? format_money($avgPer) . ' / accepted offer' : '—' }}</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Avg. extension</div>
    <div class="ia-stat-value">{{ $avgMins ? floor($avgMins / 60) . 'h ' . ($avgMins % 60) . 'm' : '—' }}</div>
    <div class="ia-stat-delta">per accepted offer</div>
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span class="ia-card-title">All offers</span>
    <div style="display:flex;gap:6px">
      @foreach(['all' => 'All', 'accepted' => 'Accepted', 'dead' => 'Declined / expired'] as $k => $label)
        <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $k]) }}" class="ia-btn ia-btn--sm {{ $filter === $k ? 'ia-btn--primary' : '' }}" style="text-decoration:none">{{ $label }}</a>
      @endforeach
    </div>
  </div>
  @if($offers->isEmpty())
    <div style="padding:22px 20px;font-size:12.5px;opacity:.55">No offers yet — they'll appear here as the scan finds eligible rentals.</div>
  @else
    <table class="ia-table">
      <thead><tr><th>Sent</th><th>Customer</th><th>Unit</th><th>Channel</th><th class="ia-num">Discount</th><th class="ia-num">Offer total</th><th>Status</th></tr></thead>
      <tbody>
        @foreach($offers as $o)
          <tr @if($o->rental) onclick="window.location='{{ route('tenant.rentals.bookings.show', $o->rental_id) }}'" style="cursor:pointer" @endif>
            <td class="ia-num">{{ $o->sent_at ? tlocal($o->sent_at) : '—' }}</td>
            <td>{{ $o->rental?->customer?->fullName() ?? '—' }}</td>
            <td>{{ $o->rental?->lines?->first()?->name_snapshot ?? '—' }}</td>
            <td>{{ $o->channel === 'manual' ? 'Manual' : 'Auto' }}</td>
            <td class="ia-num">{{ $o->discount_pct }}%</td>
            <td class="ia-num">{{ format_money($o->total_cents) }}</td>
            <td>
              @if($o->status === 'paid')<span class="ia-badge ia-badge--healthy">Accepted · paid</span>
              @elseif($o->status === 'sent')<span class="ia-badge ia-badge--out">Awaiting reply</span>
              @else<span class="ia-badge">{{ ucfirst($o->status) }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
