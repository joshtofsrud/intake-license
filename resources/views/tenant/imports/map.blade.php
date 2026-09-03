@extends('layouts.tenant.app')
@php $pageTitle = 'Map fields'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Map your columns</h1>
    <p class="ia-page-subtitle mono">{{ $import->original_filename }} ·
      {{ number_format($stats['rows']) }} rows · {{ count($preview['header']) }} columns</p>
  </div>
</div>

@if($stats['ragged'] > 0)
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    {{ $stats['ragged'] }} {{ Str::plural('row', $stats['ragged']) }} have a different number of columns
    than the header. They'll still be read, but check them in the preview.
  </div>
@endif

{{-- MARKER-IMPORT-LEGEND — the match key, named from the registry, with the
     consequence that is actually true for THIS import type. --}}
@php
  $matchLabel = $fields[$matchField]['label'] ?? $matchField;
  $isInventory = $import->type === 'inventory';
@endphp
<div class="ia-flash ia-flash--info" style="margin-bottom:14px">
  <b>Map a column to {{ $matchLabel }}</b> — it's how an existing
  {{ $isInventory ? 'item' : 'customer' }} is recognised, so you can't continue without it.
  @if($isInventory)
    A row with a blank SKU can't be identified, so it's reported as an error rather than imported.
  @else
    A row with a blank email still imports as a new customer — it just can't be matched
    against on a later import.
  @endif
  Anything you leave unmapped is ignored.
</div>

{{-- MARKER-CONSENT-IMPORT-FIX — legend: importing does NOT grant permission
     to market to these people, and nothing on this screen would say so. --}}
@if($import->type === 'customers')
<div class="ia-flash ia-flash--info" style="margin-bottom:14px">
  <b>Importing doesn't grant marketing permission.</b> These customers can be
  booked, sold to and emailed receipts straight away, but they won't receive
  campaigns until someone confirms you have permission to market to them.
  That's done once, for the whole list, on
  <a href="{{ route('tenant.consent.index') }}">Contacts &amp; consent</a>.
</div>
@endif

{{-- MARKER-IMPORT-PRESETS — preset bar. These are SEPARATE forms rendered
     after the main one and reached with the HTML5 form attribute: a nested
     form silently reroutes the outer submit, which has bitten us before. --}}
@if($presets->isNotEmpty() || $import->mapping)
<div class="ia-card" style="margin-bottom:14px">
  <div class="cbody" style="padding:13px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    @if($presets->isNotEmpty())
      <span style="font-size:12.5px;color:var(--ia-text-dim)">Saved mapping</span>
      <select class="imp-sel" name="preset_id" form="imp-preset-apply" style="width:auto;min-width:230px">
        @foreach($presets as $ps)
          <option value="{{ $ps->id }}" @selected(($applied->id ?? null) === $ps->id)>{{ $ps->name }}</option>
        @endforeach
      </select>
      <button class="ia-btn ia-btn--sm" form="imp-preset-apply" type="submit">Apply</button>
      @if($autoMatched ?? false)
        <span class="chip chip--update">Headers matched exactly</span>
      @endif
    @endif

    <span style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <input class="imp-sel" type="text" name="name" form="imp-preset-save" maxlength="80"
             placeholder="Name this mapping…" style="width:200px">
      <button class="ia-btn ia-btn--sm" form="imp-preset-save" type="submit">Save mapping</button>
    </span>
  </div>
  <div class="imp-legend">
    A saved mapping stores the column matches and the conflict rules — never the file
    or anything in it. It's offered only on {{ $import->type }} imports, and applied
    automatically when a file's header row is identical to the one it was saved from.
  </div>
</div>
@endif

<form id="mapping-form" method="POST" action="{{ route('tenant.imports.mapping', $import->id) }}">
  @csrf

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">{{ count($preview['header']) }} columns</span></div>
    <div class="imp-scroll">
      <table class="imp">
        <thead><tr>
          <th style="width:170px">Your column</th>
          <th style="width:210px">Sample</th>
          <th style="width:230px">Intake field</th>
          <th style="width:200px">When it already has a value</th>
        </tr></thead>
        <tbody>
          @foreach($preview['header'] as $i => $head)
            @php
              $chosen = $mapping[$i]['field'] ?? null;
              $dir    = $mapping[$i]['dir'] ?? '';
              $sample = $preview['sample'][0][$i] ?? '';
            @endphp
            <tr>
              <td class="mono">{{ $head !== '' ? $head : 'Column ' . ($i + 1) }}</td>
              <td><span class="imp-sample">{{ Str::limit((string) $sample, 40) }}</span></td>
              <td>
                <select name="field[{{ $i }}]" class="imp-sel">
                  <option value="">— ignore this column —</option>
                  @foreach($fields as $key => $def)
                    <option value="{{ $key }}" @selected($chosen === $key)>{{ $def['label'] }}</option>
                  @endforeach
                </select>
              </td>
              <td>
                <select name="dir[{{ $i }}]" class="imp-dir">
                  <option value="" @selected($dir === '')>Use the default</option>
                  <option value="csv" @selected($dir === 'csv')>Use CSV</option>
                  <option value="keep" @selected($dir === 'keep')>Keep mine</option>
                  <option value="blank" @selected($dir === 'blank')>Only fill blanks</option>
                </select>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  {{-- MARKER-IMPORT-TAG-CARD — three settings decide what this run does, so
       they sit together at the same size. --}}
  <div class="{{ $import->type === 'customers' ? 'imp-three' : 'imp-two' }}">
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Existing customers</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="mode" value="upsert" checked>
          <span><b>Add and update</b><span>New emails are created; ones you already have are merged.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="insert">
          <span><b>Add only</b><span>Existing customers are left alone and reported as skipped.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="update">
          <span><b>Update only</b><span>Nothing new is created. Rows with no match are listed, not dropped.</span></span></label>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Default merge direction</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="direction" value="csv" checked>
          <span><b>Use CSV</b><span>Your spreadsheet is the source of truth for every mapped field.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="blank">
          <span><b>Only fill blanks</b><span>Adds what's missing, never overwrites what someone typed.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="keep">
          <span><b>Keep mine</b><span>Reference only — useful for a dry comparison.</span></span></label>
        <p class="imp-hint" style="margin-top:8px">Any column above can override this for itself.</p>
      </div>
    </div>

    {{-- MARKER-IMPORT-TAG-CARD / MARKER-CUSTOMER-TAGS --}}
    @if($import->type === 'customers')
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Tag</span></div>
      <div class="ia-card-body">
        <input type="text" name="tag_name" maxlength="60"
               value="{{ $import->options['tag_name'] ?? '' }}"
               placeholder="e.g. The Bike Hub list"
               style="width:100%;background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:8px 11px;font:inherit;font-size:13px">

        <label class="imp-radio" style="margin-top:10px">
          <input type="radio" name="tag_scope" value="created"
                 {{ ($import->options['tag_scope'] ?? 'created') === 'created' ? 'checked' : '' }}>
          <span><b>New customers only</b><span>Rows that match someone you already have are left untagged.</span></span>
        </label>
        <label class="imp-radio">
          <input type="radio" name="tag_scope" value="touched"
                 {{ ($import->options['tag_scope'] ?? 'created') === 'touched' ? 'checked' : '' }}>
          <span><b>New and updated</b><span>Anyone in this file gets the tag, whether or not you already had them.</span></span>
        </label>

        <p class="imp-hint" style="margin-top:8px">
          Optional. Lets you find this list again later. A tag doesn't grant marketing permission.
        </p>
      </div>
    </div>
    @endif
  </div>

  @if($import->type === 'inventory')
    {{-- MARKER-IMPORT2 — stock is a movement at a location, so it needs one --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Stock &amp; records</span></div>
      <div class="ia-card-body">
        <div class="imp-two" style="margin-top:0">
          <div>
            <label class="ia-form-label">Count quantities at</label>
            <select name="location_id" class="ia-input">
              @foreach($locations as $loc)
                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
              @endforeach
            </select>
            <p class="imp-hint" style="margin-top:6px">Recorded as a counted movement here, so the
              ledger, transfers and reports stay consistent.</p>
          </div>
          <div>
            <label class="ia-form-label">If the item already has stock</label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="set" checked>
              <span><b>Set to the file's number</b><span>Records the difference as a counted adjustment.</span></span></label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="add">
              <span><b>Add to what's there</b><span>Treats the file as a received shipment.</span></span></label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="leave">
              <span><b>Leave stock alone</b></span></label>
          </div>
        </div>
        <label class="imp-radio"><input type="checkbox" name="create_categories" value="1" checked>
          <span><b>Create categories that don't exist</b><span>Matched on name. "Parts &gt; Brakes" creates the parent too.</span></span></label>
        <label class="imp-radio"><input type="checkbox" name="create_vendors" value="1" checked>
          <span><b>Create vendors that don't exist</b><span>Existing vendors are matched on name first.</span></span></label>
      </div>
    </div>
  @endif

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Check the file</button>
  </div>
</form>

{{-- MARKER-IMPORT-PRESETS — outside the mapping form on purpose. --}}
<form method="POST" action="{{ route('tenant.imports.preset.apply', $import->id) }}" id="imp-preset-apply">@csrf</form>
<form method="POST" action="{{ route('tenant.imports.preset.save', $import->id) }}" id="imp-preset-save">@csrf</form>
@include('tenant.imports._confirm')
@endsection
