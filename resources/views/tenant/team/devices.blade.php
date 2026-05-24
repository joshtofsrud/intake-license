{{-- MARKER-PATCH-129 — owner all-devices audit --}}
@extends('layouts.tenant.app')
@php $pageTitle = 'All devices'; @endphp

@push('styles')
<style>
.td-row { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.td-row:first-of-type { border-top:none; padding-top:2px; }
.td-label { font-size:13px; font-weight:500; display:flex; align-items:center; gap:8px; }
.td-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.td-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.td-back:hover { color:var(--ia-text-muted); }
.td-empty { padding:28px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
</style>
@endpush

@section('content')
<a href="{{ route('tenant.team.index') }}" class="td-back">← Team</a>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">All trusted devices</h1>
    <p class="ia-page-subtitle">{{ $devices->count() }} active {{ Str::plural('device', $devices->count()) }} across the team</p>
  </div>
  @if($devices->isNotEmpty())
  <div class="ia-page-actions">
    <form method="POST" action="{{ route('tenant.team.devices.revoke-all') }}">
      @csrf
      <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F87171"
              data-confirm="Revoke ALL trusted devices? Every browser will require email + password on next visit.">
        Revoke all
      </button>
    </form>
  </div>
  @endif
</div>

<div class="ia-card">
@if($devices->isEmpty())
  <div class="td-empty">No trusted devices anywhere on this shop.</div>
@else
  @foreach($devices as $d)
    <div class="td-row">
      <div>
        <div class="td-label">
          {{ $d->label ?: 'Unnamed device' }}
          @if($d->tenantUser)
            <span class="ia-badge" style="font-size:10px">{{ $d->tenantUser->name }}</span>
          @endif
        </div>
        <div class="td-meta">
          Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
          · IP {{ $d->ip_last_seen ?? '—' }}
          @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
        </div>
      </div>
      <form method="POST" action="{{ route('tenant.team.devices.revoke', $d->id) }}">
        @csrf
        <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F87171"
                data-confirm="Revoke this device?">Revoke</button>
      </form>
    </div>
  @endforeach
@endif
</div>
@endsection
