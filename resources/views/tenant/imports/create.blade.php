@extends('layouts.tenant.app')
@php $pageTitle = 'Import ' . $type; @endphp
{{-- MARKER-IMPORT3 — upload step for ONE chosen type. --}}

@section('content')
@include('tenant.imports._styles')

@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ $errors->first() }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import {{ $type }}</h1>
    <p class="ia-page-subtitle">Step 1 of 4 &middot; upload &rarr; map &rarr; preview &rarr; import</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.store') }}" enctype="multipart/form-data" id="imp-form">
  @csrf
  <input type="hidden" name="type" value="{{ $type }}">

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Your file</span>
      <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost ia-btn--sm"
         style="margin-left:auto">Change type</a></div>
    <div class="ia-card-body">

      <div class="imp-drop" id="imp-drop">
        <h4>Choose your CSV</h4>
        <p>CSV or tab-separated &middot; up to 20&nbsp;MB</p>
        <input type="file" name="file" id="imp-file" accept=".csv,.txt" required
               class="ia-input" style="max-width:420px;margin:12px auto 0">
      </div>

      <div class="imp-drop has" id="imp-chosen" hidden>
        <span class="imp-file-ico">CSV</span>
        <div style="flex:1;min-width:0">
          <h4 id="imp-chosen-name">file.csv</h4>
          <p id="imp-chosen-meta">&mdash;</p>
        </div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="imp-clear">Remove</button>
      </div>

      <details class="imp-ref">
        <summary>What {{ $type }} can take &mdash; {{ count($fields) }} fields</summary>
        <div class="imp-ref-grid">
          @foreach($fields as $key => $def)
            <span>{{ $def['label'] }}@if(!empty($def['match']))<b> ·required</b>@endif</span>
          @endforeach
        </div>
        <div class="imp-ref-no">
          @if($type === 'inventory')
            Not importable on purpose: the stock counts the register maintains, the distributor catalog
            fields a sync would overwrite on its next run, and price acknowledgement history.
            Stock on hand is written as a counted movement at a location you choose, not as a number on the item.
          @else
            Not importable on purpose: passwords, Stripe ids, and SMS consent &mdash; consent has to be
            evidenced, not assigned by a spreadsheet.
          @endif
        </div>
      </details>
    </div>
  </div>

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Back</a>
    <button type="submit" class="ia-btn ia-btn--primary">Upload and map fields</button>
  </div>
</form>

<script>
  // MARKER-IMPORT3 — show what was actually chosen before it's uploaded.
  (function () {
    var input  = document.getElementById('imp-file');
    var drop   = document.getElementById('imp-drop');
    var chosen = document.getElementById('imp-chosen');
    if (!input) { return; }

    function human(bytes) {
      if (bytes < 1024) { return bytes + ' B'; }
      if (bytes < 1048576) { return (bytes / 1024).toFixed(0) + ' KB'; }
      return (bytes / 1048576).toFixed(1) + ' MB';
    }

    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f) { return; }
      document.getElementById('imp-chosen-name').textContent = f.name;
      document.getElementById('imp-chosen-meta').textContent =
        human(f.size) + ' · rows and columns are counted after upload';
      drop.hidden = true;
      chosen.hidden = false;
    });

    document.getElementById('imp-clear').addEventListener('click', function () {
      input.value = '';
      chosen.hidden = true;
      drop.hidden = false;
    });
  })();
</script>
@endsection
