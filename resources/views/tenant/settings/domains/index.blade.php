{{-- MARKER-PATCH-120-PART2 — tenant domain management, list view --}}
@extends('layouts.tenant.app')

@php
  $pageTitle    = 'Domains';
  $hasCustom    = $domains->count() > 0;
  $atLimit      = ($limit !== null) && ($usage >= $limit);
  $subdomainUrl = $tenant->subdomain . '.' . config('intake.domain', 'intake.works');
@endphp

@push('styles')
<style>
  .dm-page-head { display:flex; justify-content:space-between; align-items:flex-end; padding-bottom:16px; border-bottom:1px solid var(--ia-border); margin-bottom:24px; }
  .dm-crumb { font-size:11px; color:var(--ia-text-4,#555); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px; }
  .dm-title { font-size:22px; font-weight:800; letter-spacing:-0.02em; }
  .dm-sub { font-size:13px; color:var(--ia-text-3,#888); margin-top:4px; }
  .dm-limit { font-size:12px; color:var(--ia-text-3,#888); margin-top:4px; }
  .dm-limit strong { color:var(--ia-text); }
  .dm-limit.at-limit strong { color:var(--ia-amber,#F59E0B); }

  .dm-row { display:grid; grid-template-columns:22px 1fr auto auto; gap:14px; align-items:center; padding:14px 0; border-bottom:1px solid var(--ia-border); }
  .dm-row:last-child { border-bottom:none; padding-bottom:0; }

  .dm-icon { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; flex-shrink:0; }
  .dm-icon.primary  { background:rgba(95,168,220,.12); color:#5fa8dc; }
  .dm-icon.active   { background:rgba(190,242,100,.12); color:#BEF264; }
  .dm-icon.pending  { background:rgba(245,158,11,.12); color:#F59E0B; }
  .dm-icon.error    { background:rgba(248,113,113,.12); color:#F87171; }
  .dm-icon.default  { background:rgba(255,255,255,.05); color:var(--ia-text-3,#888); }

  .dm-name { font-family:var(--ia-font-mono,monospace); font-size:14px; font-weight:600; color:var(--ia-text); }
  .dm-meta { display:flex; align-items:center; gap:10px; font-size:11.5px; color:var(--ia-text-3,#888); margin-top:3px; flex-wrap:wrap; }
  .dm-role-tag { font-size:10px; font-weight:700; padding:1px 7px; border-radius:99px; text-transform:uppercase; letter-spacing:0.04em; }
  .dm-role-tag.primary { background:rgba(95,168,220,.12); color:#5fa8dc; }
  .dm-role-tag.alias   { background:rgba(255,255,255,.05); color:var(--ia-text-3,#888); }
  .dm-role-tag.default { background:rgba(190,242,100,.08); color:#BEF264; }

  .dm-pill { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:99px; text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap; }
  .dm-pill::before { content:''; width:6px; height:6px; border-radius:50%; }
  .dm-pill.pending   { background:rgba(245,158,11,.12); color:#F59E0B; }
  .dm-pill.pending::before { background:#F59E0B; }
  .dm-pill.verifying { background:rgba(95,168,220,.12); color:#5fa8dc; }
  .dm-pill.verifying::before { background:#5fa8dc; animation:dm-pulse 1.6s infinite; }
  .dm-pill.issuing   { background:rgba(167,139,250,.12); color:#A78BFA; }
  .dm-pill.issuing::before { background:#A78BFA; animation:dm-pulse 1.6s infinite; }
  .dm-pill.active    { background:rgba(190,242,100,.12); color:#BEF264; }
  .dm-pill.active::before { background:#BEF264; }
  .dm-pill.error     { background:rgba(248,113,113,.12); color:#F87171; }
  .dm-pill.error::before { background:#F87171; }
  .dm-pill.suspended { background:rgba(255,255,255,.05); color:var(--ia-text-3,#888); }
  .dm-pill.suspended::before { background:var(--ia-text-3,#888); }
  @keyframes dm-pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }

  .dm-pitch { background:rgba(190,242,100,.03); border:1px solid rgba(190,242,100,.18); border-radius:12px; padding:24px; margin-bottom:16px; }
  .dm-pitch-row { display:flex; gap:20px; align-items:flex-start; }
  .dm-pitch-icon { font-size:28px; }
  .dm-pitch-title { font-size:14px; font-weight:700; margin-bottom:6px; }
  .dm-pitch-body { font-size:13px; color:var(--ia-text-2,#c8c8c8); line-height:1.6; margin-bottom:14px; }

  .dm-flash { padding:10px 14px; margin-bottom:16px; border-radius:8px; font-size:13px; }
  .dm-flash.success { background:rgba(120,200,120,.10); border:0.5px solid rgba(120,200,120,.25); color:#78c878; }
  .dm-flash.error { background:rgba(248,113,113,.10); border:0.5px solid rgba(248,113,113,.25); color:#F87171; }

  .dm-form-inline { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:end; }
  .dm-form-help { font-size:11.5px; color:var(--ia-text-4,#555); margin-top:4px; line-height:1.5; }

  @media (max-width: 640px) {
    .dm-row { grid-template-columns:22px 1fr; gap:10px; }
    .dm-row .dm-pill { grid-column:2; justify-self:start; }
    .dm-row .dm-action { grid-column:2; justify-self:start; }
    .dm-form-inline { grid-template-columns:1fr; }
  }
</style>
@endpush

@section('content')
<div class="ia-page-head dm-page-head">
  <div>
    <div class="dm-crumb">Settings → Domains</div>
    <h1 class="ia-page-title dm-title">Domains</h1>
    <div class="dm-sub">Connect your own domain to your Intake site. HTTPS is automatic.</div>
    @if($limit !== null)
      <div class="dm-limit @if($atLimit) at-limit @endif">
        Using <strong>{{ $usage }}</strong> of <strong>{{ $limit }}</strong> domain slots
        @if($atLimit) — upgrade your plan to add more @endif
      </div>
    @endif
  </div>
</div>

@if(session('success'))
  <div class="dm-flash success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="dm-flash error">{{ $errors->first() }}</div>
@endif

{{-- Domain list (always shows subdomain + any custom domains) --}}
<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head">
    <span class="ia-card-title">Your domains</span>
  </div>

  {{-- Default subdomain row — always present, never editable --}}
  <div class="dm-row">
    <div class="dm-icon default">{!! $hasCustom ? '✓' : '★' !!}</div>
    <div>
      <div class="dm-name">{{ $subdomainUrl }}</div>
      <div class="dm-meta">
        <span class="dm-role-tag default">Default</span>
        <span>Always works · can't be removed</span>
      </div>
    </div>
    <span class="dm-pill active">Active</span>
    <span style="font-size:11px;color:var(--ia-text-4,#555)">—</span>
  </div>

  {{-- Custom domains --}}
  @foreach($domains as $d)
    @php
      $pillClass = match($d->status) {
        'active'       => 'active',
        'pending_dns'  => 'pending',
        'verifying'    => 'verifying',
        'issuing_cert' => 'issuing',
        'error'        => 'error',
        'suspended'    => 'suspended',
        default        => 'pending',
      };
      $iconClass = match($d->status) {
        'active'  => 'active',
        'error'   => 'error',
        'suspended' => 'default',
        default   => 'pending',
      };
      $iconChar = match($d->status) {
        'active' => $d->is_primary ? '★' : '↗',
        'error'  => '!',
        'suspended' => '⏸',
        default  => '⋯',
      };
    @endphp
    <div class="dm-row">
      <div class="dm-icon {{ $iconClass }}">{{ $iconChar }}</div>
      <div>
        <div class="dm-name">{{ $d->hostname }}</div>
        <div class="dm-meta">
          @if($d->is_primary)
            <span class="dm-role-tag primary">Primary</span>
          @else
            <span class="dm-role-tag alias">Alias</span>
          @endif

          @if($d->status === 'active')
            <span>Active since {{ $d->activated_at?->format('M j') ?? 'recently' }}</span>
          @elseif($d->status === 'pending_dns')
            <span>Waiting for DNS — add records to finish setup</span>
          @elseif($d->status === 'verifying')
            <span>Verifying ownership</span>
          @elseif($d->status === 'issuing_cert')
            <span>Issuing HTTPS certificate</span>
          @elseif($d->status === 'error')
            <span style="color:#F87171">{{ $d->last_error_message ?: 'See details to resolve' }}</span>
          @elseif($d->status === 'suspended')
            <span>Suspended by platform admin</span>
          @endif
        </div>
      </div>
      <span class="dm-pill {{ $pillClass }}">{{ ucfirst(str_replace('_', ' ', $d->status)) }}</span>
      <a href="{{ route('tenant.domains.show', ['subdomain' => $tenant->subdomain, 'id' => $d->id]) }}"
         class="ia-btn ia-btn-secondary dm-action"
         style="padding:6px 12px;font-size:12px">Manage</a>
    </div>
  @endforeach
</div>

{{-- Add domain — pitch card if no custom domains yet, compact form otherwise --}}
@if(! $hasCustom)
  <div class="dm-pitch">
    <div class="dm-pitch-row">
      <div class="dm-pitch-icon">🌐</div>
      <div style="flex:1">
        <div class="dm-pitch-title">Bring your own domain</div>
        <div class="dm-pitch-body">
          Use a domain you already own — like <code style="font-family:var(--ia-font-mono,monospace);font-size:12px">{{ $tenant->subdomain }}.com</code>, <code style="font-family:var(--ia-font-mono,monospace);font-size:12px">shop.{{ $tenant->subdomain }}.com</code>, or anything you've registered. Customers see your brand, not ours. HTTPS, renewals, and edge caching are handled automatically.
        </div>
        @include('tenant.settings.domains._add_form', ['tenant' => $tenant, 'atLimit' => $atLimit])
      </div>
    </div>
  </div>
@else
  <div class="ia-card">
    <div class="ia-card-head">
      <span class="ia-card-title">Add another domain</span>
    </div>
    @if($atLimit)
      <p style="font-size:13px;color:var(--ia-text-3,#888);">
        You've used all {{ $limit }} of your domain slots. Upgrade your plan to add more.
      </p>
    @else
      @include('tenant.settings.domains._add_form', ['tenant' => $tenant, 'atLimit' => $atLimit])
    @endif
  </div>
@endif

@endsection
