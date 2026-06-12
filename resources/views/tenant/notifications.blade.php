@extends('layouts.tenant.app')
@php $pageTitle = 'Notifications'; @endphp

{{-- MARKER-PATCH-231 — full notifications page. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Notifications</h1>
    <p class="ia-page-subtitle">Everything that's needed your attention.</p>
  </div>
  <a href="{{ route('tenant.alerts.prefs') }}" class="ia-btn">Settings</a>
</div>

@if($alerts->isEmpty())
  <div class="ia-card" style="padding:40px;text-align:center">
    <p style="font-size:14px;opacity:.6">You're all caught up — nothing here yet.</p>
  </div>
@else
  <div class="ia-card" style="padding:0;overflow:hidden">
    @foreach($alerts as $a)
      @php $href = $a->link ?: '#'; @endphp
      <a href="{{ $href }}" style="display:flex;justify-content:space-between;gap:14px;padding:13px 18px;border-bottom:.5px solid var(--ia-border);text-decoration:none;color:inherit;{{ $a->read_at ? '' : 'background:rgba(120,160,240,.06)' }}">
        <div>
          <div style="font-size:13.5px;font-weight:600;{{ $a->is_critical ? 'color:#E0573E' : '' }}">{{ $a->title }}</div>
          @if($a->body)<div style="font-size:12px;opacity:.6;margin-top:2px">{{ $a->body }}</div>@endif
        </div>
        <div style="font-size:11px;opacity:.4;white-space:nowrap">{{ $a->created_at?->diffForHumans() }}</div>
      </a>
    @endforeach
  </div>
  <div style="margin-top:16px">{{ $alerts->links() }}</div>
@endif

@endsection
