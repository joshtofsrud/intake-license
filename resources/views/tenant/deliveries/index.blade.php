@extends('layouts.tenant.app')
@section('title', 'Schedule · Deliveries')

{{-- MARKER-PATCH-152A — Stub. Real day/week timelines ship in 152-b. --}}

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Schedule</h1>
    <p class="ia-page-subtitle">Deliveries · {{ $date->format('l, F j, Y') }}</p>
  </div>
</div>

<x-tenant.schedule-tabs active="deliveries" />

<div style="background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 12px; padding: 48px 24px; text-align: center; color: var(--ia-text-muted);">
  <div style="font-size: 32px; opacity: .35; margin-bottom: 12px;">🚚</div>
  <div style="font-size: 16px; color: var(--ia-text); font-weight: 600; margin-bottom: 8px;">Deliveries</div>
  <div style="font-size: 13px; max-width: 480px; margin: 0 auto 16px;">
    Internal pickup &amp; dropoff scheduling for your shop. The full day &amp; week timelines, create flow, and customer notifications land in the next two patches.
  </div>
  <a href="{{ route('tenant.deliveries.resources.index') }}"
     style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; background: rgba(190,242,100,.08); color: var(--ia-accent, #BEF264); border: 1px solid rgba(190,242,100,.2); border-radius: 6px; font-size: 12.5px; font-weight: 600; text-decoration: none;">
    Set up delivery resources &rarr;
  </a>
</div>
@endsection