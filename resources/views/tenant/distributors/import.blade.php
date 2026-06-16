@extends('layouts.tenant.app')
@php $pageTitle = 'Import from HLC'; @endphp

{{-- MARKER-PATCH-HLC7B --}}

@push('styles')
<style>
.im-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;margin-bottom:18px}
.im-h{font-size:15px;font-weight:600;margin:0 0 4px}
.im-sub{font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px;line-height:1.5}
.im-row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
.im-field{flex:1;min-width:200px}
.im-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:6px}
.im-input{width:100%;background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);padding:9px 11px;color:var(--ia-text);font-size:13px}
.im-input:focus{outline:none;border-color:var(--ia-accent)}
.im-check{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ia-text-muted);margin-top:12px}
.im-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.im-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.im-btn:disabled{opacity:.5;cursor:not-allowed}
.im-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:6px 0 16px}
.im-stat{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:13px 14px;text-align:center}
.im-stat .v{font-size:24px;font-weight:700;font-family:var(--ia-mono)}
.im-stat .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-top:3px}
.im-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.im-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.im-err{background:rgba(226,75,74,.12);border-color:rgba(226,75,74,.4);color:#f0a3a3}
.im-info{background:var(--ia-accent-soft);border-color:rgba(190,242,100,.2);color:var(--ia-text-muted)}
</style>
@endpush

@section('content')
<div style="max-width:880px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>
  @include('layouts.tenant._inventory-tabs')

  @if(session('error'))<div class="im-banner im-err">{{ session('error') }}</div>@endif

  <div class="im-card">
    <h2 class="im-h">Import items</h2>
    <p class="im-sub">Pick a brand and/or category, preview what comes in, then import. Items land catalog-only (no stock) — your normal product search finds them after. <b>{{ number_format($catalogTotal) }}</b> HLC items in the shared catalog.</p>

    <form method="POST" action="{{ route('tenant.distributors.import.run') }}">
      @csrf
      <input type="hidden" name="mode" value="preview" id="im-mode">
      <div class="im-row">
        <div class="im-field"><label>Brand</label>
          <select class="im-input" name="brand">
            <option value="">— any brand —</option>
            @foreach($brands as $b)<option value="{{ $b }}" @selected(($filters['brand'] ?? '')===$b)>{{ $b }}</option>@endforeach
          </select>
        </div>
        <div class="im-field"><label>Category</label>
          <select class="im-input" name="category">
            <option value="">— any category —</option>
            @foreach($categories as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? '')===$c)>{{ $c }}</option>@endforeach
          </select>
        </div>
      </div>
      <label class="im-check"><input type="checkbox" name="include_unsellable" value="1" @checked(!empty($filters['include_unsellable']))> Include discontinued / unsellable items</label>

      <div style="margin-top:16px;display:flex;gap:10px">
        <button class="im-btn primary" type="submit" onclick="document.getElementById('im-mode').value='preview'">Preview</button>
      </div>
      <p class="im-sub" style="margin:10px 0 0">Choose at least a brand or a category. Large pulls are capped at 2,000 per run — narrow the filter to get everything.</p>
    </form>
  </div>

  @isset($result)
    <div class="im-card">
      <h2 class="im-h">{{ $mode === 'commit' ? 'Import receipt' : 'Preview' }}</h2>
      <p class="im-sub">
        {{ $filters['brand'] ?? 'Any brand' }} · {{ $filters['category'] ?? 'any category' }}
        @if(!empty($filters['include_unsellable'])) · incl. unsellable @endif
      </p>

      <div class="im-stats">
        <div class="im-stat"><div class="v" style="color:var(--ia-accent)">{{ number_format($result['created']) }}</div><div class="k">{{ $mode==='commit' ? 'Created' : 'To create' }}</div></div>
        <div class="im-stat"><div class="v">{{ number_format($result['merged']) }}</div><div class="k">{{ $mode==='commit' ? 'Source added' : 'Already carried' }}</div></div>
        <div class="im-stat"><div class="v" style="color:var(--ia-text-dim)">{{ number_format($result['skipped']) }}</div><div class="k">Skipped</div></div>
      </div>

      @if($mode === 'commit')
        <div class="im-banner im-ok">Done. {{ number_format($result['created']) }} new item(s) added to your catalog.</div>
        <a class="im-btn" href="{{ route('tenant.inventory.index') }}">Go to Inventory</a>
      @else
        @if($result['created'] + $result['merged'] === 0)
          <div class="im-banner im-info">Nothing new to import for this filter — you already carry these, or none match.</div>
        @else
          <div class="im-banner im-info">Preview only — nothing imported yet. Confirm to add {{ number_format($result['created']) }} new item(s).</div>
          <form method="POST" action="{{ route('tenant.distributors.import.run') }}">
            @csrf
            <input type="hidden" name="mode" value="commit">
            <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
            <input type="hidden" name="category" value="{{ $filters['category'] ?? '' }}">
            <input type="hidden" name="include_unsellable" value="{{ !empty($filters['include_unsellable']) ? '1' : '' }}">
            <button class="im-btn primary" type="submit">Import {{ number_format($result['created']) }} item(s)</button>
          </form>
        @endif
      @endif
    </div>
  @endisset
</div>
@endsection
