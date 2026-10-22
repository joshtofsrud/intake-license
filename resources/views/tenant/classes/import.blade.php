@extends('layouts.tenant.app')
@php $pageTitle = 'Class Import'; @endphp
{{-- MARKER-CLASSES-P12: bringing a studio over from another system --}}

@push('styles')
<style>
.ci-wrap{max-width:820px}
.ci-sec{display:grid;grid-template-columns:220px 1fr;gap:10px 28px;padding:22px 0;border-bottom:0.5px solid var(--ia-border)}
.ci-sec h2{font-size:14px;font-weight:600;margin:0 0 4px}
.ci-help{font-size:12.5px;color:var(--ia-text-muted);line-height:1.5;margin:0}
.ci-line{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:13.5px;margin-bottom:12px}
.ci-row{display:flex;flex-wrap:wrap;gap:4px 14px;padding:9px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px}
.ci-row span{color:var(--ia-text-muted)}
.ci-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:16px}
@media(max-width:720px){.ci-sec{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Import</h1>
    <p class="ia-page-subtitle">Moving your studio over from another system.</p>
  </div>
  <div class="ia-page-head-right"><a href="{{ route('tenant.classes.settings') }}" class="ia-btn ia-btn--secondary ia-btn--sm">‹ Class settings</a></div>
</div>

@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:12px">{{ session('success') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:12px">{{ $errors->first() }}</div>@endif

<div class="ci-wrap">
  <section class="ci-sec">
    <div><h2>Customers</h2><p class="ci-help">Names, emails and phone numbers, from any CSV.</p></div>
    <div>
      <div class="ci-line"><a href="{{ route('tenant.imports.create', ['type' => 'customers']) }}" class="ia-btn ia-btn--primary ia-btn--sm">Import customers</a></div>
      <p class="ci-help">Do this first, so passes and memberships have someone to belong to.</p>
    </div>
  </section>

  <section class="ci-sec">
    <div><h2>Passes and memberships</h2><p class="ci-help">Class packs with credits left, and memberships with their renewal dates.</p></div>
    <div>
      <form method="POST" action="{{ route('tenant.classes.import.upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="ci-line">Moving from
          <select name="source" class="ia-input" style="width:auto" required>
            @foreach(\App\Services\ClassImport::SOURCES as $k => $label)<option value="{{ $k }}" @selected(old('source') === $k)>{{ $label }}</option>@endforeach
          </select>
        </div>
        <div class="ci-line"><input type="file" name="export" required accept=".csv,.txt,.xlsx,.xls,.zip"></div>
        <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm">Upload the export</button>
      </form>
      <p class="ci-legend">Nothing is imported yet. The file is kept privately so it can be mapped and brought in with you. Your current customers, passes and classes aren't touched.</p>

      @if($received)
        <div style="margin-top:18px">
          @foreach($received as $r)
            <div class="ci-row"><b>{{ $r['name'] }}</b><span>{{ \App\Services\ClassImport::SOURCES[$r['source']] ?? $r['source'] }}</span><span>{{ number_format(($r['bytes'] ?? 0) / 1024, 0) }} KB</span><span>{{ tlocal(\Carbon\Carbon::parse($r['uploaded_at']), 'M j, g:i A') }}</span><span>Received</span></div>
          @endforeach
        </div>
      @endif
    </div>
  </section>
</div>
@endsection
