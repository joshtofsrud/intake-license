@extends('layouts.tenant.app')
@section('title', 'Delivery resources')

{{-- MARKER-PATCH-152A — Delivery resources management. --}}

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Delivery resources</h1>
    <p class="ia-page-subtitle">Vehicles, drivers, or in-shop drop slots that you assign deliveries to. Separate from appointment resources.</p>
  </div>
  <div class="ia-page-head-right">
    <a href="{{ route('tenant.deliveries.index') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: var(--ia-surface); border: 0.5px solid var(--ia-border); color: var(--ia-text-2, rgba(255,255,255,.78)); border-radius: 4px; font-size: 12.5px; text-decoration: none;">
      &larr; Back to deliveries
    </a>
  </div>
</div>

@if(session('success'))
  <div style="margin-bottom: 16px; padding: 10px 14px; background: rgba(134,239,172,.08); border: 0.5px solid rgba(134,239,172,.2); border-radius: 6px; color: #86EFAC; font-size: 13px;">
    {{ session('success') }}
  </div>
@endif

<div style="background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 8px; padding: 18px; margin-bottom: 20px;">
  <div style="font-size: 13px; font-weight: 600; margin-bottom: 12px;">Add delivery resource</div>
  <form method="POST" action="{{ route('tenant.deliveries.resources.store') }}" style="display: grid; grid-template-columns: 2fr 2fr 100px auto; gap: 10px; align-items: end;">
    @csrf
    <div>
      <label class="ia-label" style="display:block; margin-bottom:5px; font-size: 11px; color: var(--ia-text-muted);">Name</label>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Van #1" class="ia-input" style="width:100%">
    </div>
    <div>
      <label class="ia-label" style="display:block; margin-bottom:5px; font-size: 11px; color: var(--ia-text-muted);">Subtitle (optional)</label>
      <input type="text" name="subtitle" maxlength="160" placeholder="e.g. Primary route · Josh" class="ia-input" style="width:100%">
    </div>
    <div>
      <label class="ia-label" style="display:block; margin-bottom:5px; font-size: 11px; color: var(--ia-text-muted);">Color</label>
      <input type="color" name="color_hex" value="#60A5FA" class="ia-input" style="width:100%; height: 38px; padding: 2px;">
    </div>
    <button type="submit" class="ia-btn ia-btn--primary">Add</button>
  </form>
</div>

@if($resources->isEmpty())
  <div style="background: var(--ia-surface); border: 0.5px dashed var(--ia-border); border-radius: 8px; padding: 48px 20px; text-align: center; color: var(--ia-text-muted); font-size: 13px;">
    No delivery resources yet. Add one above to get started.
  </div>
@else
  <div style="background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 8px; overflow: hidden;">
    @foreach($resources as $res)
      <div style="display: grid; grid-template-columns: 28px 1fr auto; gap: 14px; align-items: center; padding: 14px 18px; border-bottom: 0.5px solid var(--ia-border);">
        <div style="width: 18px; height: 18px; border-radius: 4px; background: {{ $res->color_hex }};"></div>
        <div>
          <div style="font-weight: 600; font-size: 13.5px;">{{ $res->name }}</div>
          <div style="font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px;">
            @if($res->subtitle){{ $res->subtitle }} · @endif{{ $res->is_active ? 'Active' : 'Archived' }}
          </div>
        </div>
        <div style="display: flex; gap: 6px;">
          @if($res->is_active)
            <form method="POST" action="{{ route('tenant.deliveries.resources.destroy', $res->id) }}" style="margin:0;">
              @csrf @method('DELETE')
              <button type="submit"
                      onclick="return confirm('Archive this delivery resource? Existing deliveries assigned to it stay linked.')"
                      style="background: transparent; border: 0; color: var(--ia-text-muted); font-size: 12px; padding: 4px 8px; border-radius: 4px; cursor: pointer;">
                Archive
              </button>
            </form>
          @endif
        </div>
      </div>
    @endforeach
  </div>
@endif

<div style="margin-top: 18px; padding: 14px 16px; background: var(--ia-surface); border: 0.5px dashed var(--ia-border); border-radius: 8px; font-size: 12px; color: var(--ia-text-muted);">
  <strong style="color: var(--ia-text-2, rgba(255,255,255,.78));">Note:</strong>
  Capacity-mode tenants can skip this page entirely — deliveries do not require a resource assignment in that mode.
</div>
@endsection