@extends('layouts.tenant.app')
@php $pageTitle = ($unit->identifier ?: 'Unit') . ' — ' . ($unit->model?->name ?? 'Fleet'); @endphp

{{-- MARKER-PATCH-235 — unit detail: the serial's whole story. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'fleet'])

@php
  [$uBg, $uColor, $uLabel] = match ($derived) {
      'out'         => ['rgba(91,163,208,.13)', '#5BA3D0', 'out'],
      'reserved'    => ['rgba(224,168,46,.13)', '#E0A82E', 'reserved'],
      'maintenance' => ['rgba(224,87,62,.13)', '#E0573E', 'maintenance'],
      'retired'     => ['rgba(255,255,255,.06)', 'rgba(255,255,255,.45)', 'retired'],
      default       => ['rgba(123,201,111,.13)', '#7BC96F', 'available'],
  };
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title" style="display:flex;align-items:center;gap:10px">
      {{ $unit->identifier ?: 'Unit' }} — {{ $unit->model?->name }}{{ $unit->size ? ' (' . $unit->size . ')' : '' }}
      <span style="font-size:10.5px;font-weight:600;border-radius:999px;padding:2.5px 10px;display:inline-flex;align-items:center;gap:6px;background:{{ $uBg }};color:{{ $uColor }}"><span style="width:5px;height:5px;border-radius:50%;background:currentColor"></span>{{ $uLabel }}</span>
    </h1>
    <p class="ia-page-subtitle">{{ $unit->category?->name }}{{ $unit->name ? ' · ' . $unit->name : '' }} · in fleet since {{ tlocal_date($unit->created_at) }}{{ $unit->conditionTemplate ? ' · ' . $unit->conditionTemplate->name . ' checklist' : '' }}</p>
  </div>
  <a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn">Back to fleet</a>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:22px">
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Utilization 30d</div>
    <div style="font-size:23px;font-weight:500;line-height:1">{{ $utilizationPct }}%</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">rented time ÷ window</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Revenue lifetime</div>
    <div style="font-size:23px;font-weight:500;line-height:1">{{ format_money($lifetimeCents) }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">{{ $rentals->count() }}{{ $rentals->count() === 25 ? '+' : '' }} rentals</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Flagged returns</div>
    <div style="font-size:23px;font-weight:500;line-height:1;{{ $flaggedReturns > 0 ? 'color:#E0A82E' : '' }}">{{ $flaggedReturns }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:6px">in-checks with flags</div>
  </div>
  <div class="ia-card" style="padding:15px 16px">
    <div class="ia-label" style="margin-bottom:8px">Rates</div>
    <div style="font-size:14px;font-weight:600;line-height:1.5">{{ $unit->effectiveDailyCents() ? format_money($unit->effectiveDailyCents()) . '/day' : '—' }}</div>
    <div style="font-size:11.5px;opacity:.5;margin-top:2px">deposit {{ format_money($unit->effectiveDepositCents()) }}</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start" class="unit-cols">
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Rental history</span></div>
    @if($rentals->isEmpty())
      <div style="padding:24px 16px;font-size:12.5px;opacity:.55">Never been out. Its day will come.</div>
    @else
      <div style="display:grid;grid-template-columns:100px 1.2fr 1.2fr 110px 80px;gap:10px;padding:9px 16px;border-bottom:.5px solid var(--ia-border);font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">
        <span>Rental</span><span>Customer</span><span>Window</span><span>Status</span><span style="text-align:right">Revenue</span>
      </div>
      @foreach($rentals as $r)
        @php
          $inCheck = $r->conditionChecks->firstWhere('phase', 'check_in');
          $rev = (int) $r->lines->sum('line_total_cents');
        @endphp
        <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}" style="display:grid;grid-template-columns:100px 1.2fr 1.2fr 110px 80px;gap:10px;align-items:center;padding:10px 16px;border-bottom:.5px solid var(--ia-border);text-decoration:none;color:inherit">
          <span style="font-size:12px;opacity:.6;font-family:var(--ia-font-mono,monospace)">{{ $r->rental_number }}</span>
          <span style="font-size:12.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->customer?->first_name }} {{ $r->customer?->last_name }}</span>
          <span style="font-size:11.5px;opacity:.6">{{ tlocal_date($r->starts_at, 'M j') }} – {{ tlocal_date($r->returned_at ?? $r->due_at, 'M j') }}</span>
          <span style="display:flex;align-items:center;gap:5px">
            @include('tenant.rentals._status-pill', ['rental' => $r])
            @if($inCheck?->flagged)<span title="flagged at return" style="color:#E0A82E;font-size:11px">⚑</span>@endif
          </span>
          <span style="font-size:12.5px;text-align:right">{{ format_money($rev) }}</span>
        </a>
      @endforeach
    @endif
  </div>

  <div>
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Notes &amp; maintenance log</span>
      @if($unit->notes)
        <p style="font-size:12.5px;margin-top:10px;white-space:pre-wrap;line-height:1.6">{{ $unit->notes }}</p>
      @else
        <p style="font-size:12.5px;opacity:.5;margin-top:10px">Nothing logged. Return-flow maintenance routing writes dated lines here automatically.</p>
      @endif
      <p style="font-size:11px;opacity:.45;margin-top:10px">Status changes (clear maintenance, retire) happen inline on the <a href="{{ route('tenant.rentals.fleet') }}" style="color:var(--ia-accent);text-decoration:none">Fleet page</a>.</p>
    </div>

    @if($photoChecks->isNotEmpty())
    <div class="ia-card" style="padding:16px">
      <span class="ia-label">Recent check photos</span>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;font-size:12.5px">
        @foreach($photoChecks as $check)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span style="opacity:.75">{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} · {{ tlocal_date($check->performed_at) }}{{ $check->flagged ? ' ⚑' : '' }}</span>
            <span>
              @foreach($check->photos as $pi => $p)
                <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">{{ $pi + 1 }}</a>{{ !$loop->last ? ' ' : '' }}
              @endforeach
            </span>
          </div>
        @endforeach
      </div>
    </div>
    @endif
  </div>
</div>

<style>@media(max-width:980px){.unit-cols{grid-template-columns:1fr !important}}</style>

@endsection
